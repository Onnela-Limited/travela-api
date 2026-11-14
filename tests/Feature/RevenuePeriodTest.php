<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Schema, Http};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RevenuePeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-11-14 12:00:00', 'UTC'));
        Http::fake(); Http::preventStrayRequests();
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        Schema::table('users', fn(Blueprint $t) => $t->string('role')->default('user'));
        Schema::create('orders', function(Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('status'); $t->decimal('total_amount',12,2); $t->string('currency'); $t->timestamps(); });
        Schema::create('trips', function(Blueprint $t) { $t->id(); $t->unsignedBigInteger('order_id'); $t->string('destination_country'); });
        Schema::create('bundles', function(Blueprint $t) { $t->id(); $t->string('name'); $t->string('alias'); $t->string('currency'); });
        Schema::create('order_items', function(Blueprint $t) { $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('bundle_id'); $t->string('type'); $t->string('bundle_name'); $t->string('currency'); $t->decimal('price',12,2); });
        DB::connection()->getPdo()->sqliteCreateFunction('DATE_FORMAT', static function($date,$format) { return date($format === '%Y-%m' ? 'Y-m' : 'M Y', strtotime($date)); }, 2);
        DB::table('bundles')->insert(['id'=>1,'name'=>'Nomad','alias'=>'Nomad','currency'=>'USD']);
        Sanctum::actingAs(User::factory()->create(['role'=>'admin']));
    }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    private function order(string $date, int $amount, string $status = 'paid'): void
    {
        $id = DB::table('orders')->insertGetId(['user_id'=>auth()->id(),'status'=>$status,'currency'=>'USD','total_amount'=>$amount,'created_at'=>$date,'updated_at'=>$date]);
        DB::table('trips')->insert(['order_id'=>$id,'destination_country'=>'TZ']);
        DB::table('order_items')->insert(['order_id'=>$id,'bundle_id'=>1,'type'=>'bundle','bundle_name'=>'Nomad','currency'=>'USD','price'=>$amount]);
    }
    public function test_month_boundaries_scope_totals_breakdowns_and_order_rows(): void
    {
        $this->order('2026-10-31 23:59:59',100);
        $this->order('2026-11-01 00:00:00',10);
        $this->order('2026-11-30 23:59:59',20);
        $this->order('2026-12-01 00:00:00',200);
        $this->order('2026-11-14 12:00:00',50,'failed');
        $this->order('2026-11-14 12:00:00',80,'draft');
        $this->getJson('/api/admin/revenue/stats?period=this_month')->assertOk()
            ->assertJsonPath('data.reporting_period.key','this_month')
            ->assertJsonPath('data.reporting_period.label','November 2026')
            ->assertJsonPath('data.total_revenue',30)->assertJsonPath('data.total_orders',3)
            ->assertJsonPath('data.average_order_value',15)
            ->assertJsonPath('data.revenue_by_bundle.0.revenue',30)
            ->assertJsonPath('data.revenue_by_country.0.revenue',30)
            ->assertJsonCount(2,'data.orders_all_time');
    }
    public function test_all_time_and_legacy_default_keep_historical_totals(): void
    {
        $this->order('2026-10-31 23:59:59',100); $this->order('2026-11-14 12:00:00',20);
        foreach (['','?period=all_time'] as $query) $this->getJson('/api/admin/revenue/stats'.$query)->assertOk()
            ->assertJsonPath('data.reporting_period.key','all_time')->assertJsonPath('data.total_revenue',120)->assertJsonCount(2,'data.orders_all_time');
    }
    public function test_empty_month_has_zero_totals_without_historical_orders(): void
    {
        $this->order('2026-10-31 23:59:59',100);
        $this->getJson('/api/admin/revenue/stats?period=this_month')->assertOk()->assertJsonPath('data.total_revenue',0)->assertJsonPath('data.total_orders',0)->assertJsonCount(0,'data.orders_all_time')->assertJsonCount(0,'data.revenue_by_country');
    }
    public function test_invalid_period_and_non_admin_access_are_rejected(): void
    {
        $this->getJson('/api/admin/revenue/stats?period=anything')->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create(['role'=>'user']));
        $this->getJson('/api/admin/revenue/stats?period=this_month')->assertForbidden();
    }
}
