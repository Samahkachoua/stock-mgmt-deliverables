<?php

use App\Livewire\Settings\Branches;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Support\Format;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Settings › Branches: the manager email (customer_branch.manager_email).
 * Required, an email, at most 320 characters, one branch per customer per
 * email; existing branches without one still show.
 */

beforeEach(function () {
    $this->customer = Customer::factory()->create(['name' => 'Acme Foods']);
    $this->manager = userWithRole('MANAGER');
});

function newBranch(object $test, array $values): Testable
{
    $form = Livewire::actingAs($test->manager)->test(Branches::class)->call('add')
        ->set('form.customer_id', $test->customer->id);

    foreach ($values as $field => $value) {
        $form->set('form.'.$field, $value);
    }

    return $form->call('save');
}

it('saves the manager email trimmed and lower case, and records it', function () {
    newBranch($this, ['name' => 'Harbour', 'code' => 'HB', 'manager_email' => '  Rana@Acme.TEST '])
        ->assertHasNoErrors()
        ->assertDispatched('toast', fn (string $event, array $params) => $params['toast']['type'] === 'success');

    $branch = CustomerBranch::where('code', 'HB')->sole();
    $audit = DB::table('audit_log')->where('entity', 'customer_branch')->where('entity_id', $branch->id)->sole();

    expect($branch->manager_email)->toBe('rana@acme.test')
        ->and($audit->action)->toBe('CREATE')
        ->and(json_decode($audit->after_data, true)['manager_email'])->toBe('rana@acme.test');
});

it('edits the manager email after saving, and records the change', function () {
    $branch = CustomerBranch::create(['customer_id' => $this->customer->id, 'code' => 'HB', 'name' => 'Harbour', 'manager_email' => 'old@acme.test']);

    Livewire::actingAs($this->manager)->test(Branches::class)
        ->call('edit', $branch->id)
        ->assertSet('form.manager_email', 'old@acme.test')
        ->set('form.manager_email', 'new@acme.test')
        ->call('save')
        ->assertHasNoErrors();

    $audit = DB::table('audit_log')->where('entity', 'customer_branch')->where('entity_id', $branch->id)->where('action', 'UPDATE')->sole();

    expect($branch->fresh()->manager_email)->toBe('new@acme.test')
        ->and(json_decode($audit->before_data, true)['manager_email'])->toBe('old@acme.test')
        ->and(json_decode($audit->after_data, true)['manager_email'])->toBe('new@acme.test');
});

it('refuses a missing, invalid or too long manager email, with a toast', function (string $value, string $rule, string $key, array $replace) {
    $message = e(__($key, $replace));

    newBranch($this, ['name' => 'Harbour', 'code' => 'HB', 'manager_email' => $value])
        ->assertHasErrors(['form.manager_email' => $rule])
        ->assertSee($message, false)
        ->assertDispatched('toast', fn (string $event, array $params) => $params['toast']['type'] === 'error'
            && $params['toast']['message'] === trans_choice('feedback.fields_need_attention', 1, ['count' => Format::qty(1)]));

    expect(CustomerBranch::count())->toBe(0);
})->with([
    'missing' => ['', 'required', 'settings.branches.errors.manager_email_required', []],
    'not an email' => ['rana at acme', 'email', 'settings.branches.errors.manager_email_invalid', []],
    'over 320 characters' => [str_repeat('a', 311).'@acme.test', 'max', 'settings.errors.too_long', ['max' => 320]],
]);

it('keeps a manager email to one branch per customer, in the app and the database', function () {
    $other = Customer::factory()->create();
    CustomerBranch::create(['customer_id' => $this->customer->id, 'code' => 'HB', 'name' => 'Harbour', 'manager_email' => 'rana@acme.test']);

    // The same customer: refused, whatever the case.
    newBranch($this, ['name' => 'Downtown', 'code' => 'DT', 'manager_email' => 'RANA@acme.test'])
        ->assertHasErrors(['form.manager_email' => 'unique'])
        ->assertSee(e(__('settings.branches.errors.manager_email_taken')), false);

    // Another customer: allowed.
    Livewire::actingAs($this->manager)->test(Branches::class)->call('add')
        ->set('form.customer_id', $other->id)->set('form.name', 'Downtown')->set('form.code', 'DT')->set('form.manager_email', 'rana@acme.test')
        ->call('save')
        ->assertHasNoErrors();

    expect(fn () => DB::transaction(fn () => DB::table('customer_branch')->insert([
        'customer_id' => $this->customer->id, 'code' => 'XX', 'name' => 'Copy', 'manager_email' => 'Rana@Acme.test',
    ])))->toThrow(QueryException::class, 'uq_customer_branch_manager_email');
});

it('lists the manager email, and shows branches saved before it without one', function () {
    CustomerBranch::create(['customer_id' => $this->customer->id, 'code' => 'HB', 'name' => 'Harbour', 'manager_email' => 'rana@acme.test']);
    $old = CustomerBranch::create(['customer_id' => $this->customer->id, 'code' => 'OL', 'name' => 'Old Town']);

    $page = Livewire::actingAs($this->manager)->test(Branches::class)->call('loadRows')
        ->assertSee(__('settings.branches.manager_email'))
        ->assertSee('rana@acme.test')
        ->assertSee('Old Town')
        ->assertSee(__('settings.branches.no_manager_email'));

    // Opening it to edit asks for the email before it can be saved again.
    $page->call('edit', $old->id)->assertSet('form.manager_email', '')
        ->call('save')
        ->assertHasErrors(['form.manager_email' => 'required']);

    expect($old->fresh()->manager_email)->toBeNull();
});

it('shows the field in Arabic, right to left for the email', function () {
    app()->setLocale('ar');

    Livewire::actingAs($this->manager)->test(Branches::class)->call('add')
        ->assertSee('البريد الإلكتروني للمدير')
        ->assertSeeHtml('id="field-form-manager_email"')
        ->assertSeeHtml('type="email"');
});
