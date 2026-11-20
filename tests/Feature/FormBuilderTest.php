<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\UserRole;
use App\Filament\Resources\Forms\FormResource;
use App\Filament\Resources\Forms\Pages\CreateForm;
use App\Filament\Resources\Forms\Pages\EditForm;
use App\Filament\Resources\FormSubmissions\FormSubmissionResource;
use App\Mail\BookingMail;
use App\Mail\FormSubmissionMail;
use App\Models\Booking;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Page;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Support\Access;
use App\Support\MailPlaceholders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * The form builder: the fixed booking and contact forms with own fields
 * added, and own forms shown below a page. Submissions are stored per field
 * key and mailed to the recipients.
 */
class FormBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-02-01 10:00:00');
        Mail::fake();
        Setting::set('company', 'email', 'info@kustverhuur.test');
    }

    /** An own field as the builder saves it. */
    private function field(string $type, string $label, array $extra = []): array
    {
        return ['type' => $type, 'label' => ['en' => $label], 'required' => false, 'width' => 'full', ...$extra];
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_the_booking_and_contact_forms_exist_and_cannot_be_deleted(): void
    {
        $booking = Form::fixed(Form::BOOKING);
        $contact = Form::fixed(Form::CONTACT);

        $this->assertSame(2, Form::query()->count());
        $this->assertTrue($booking->isSystem());

        $this->expectException(LogicException::class);
        $contact->delete();
    }

    public function test_an_own_form_can_be_deleted(): void
    {
        $form = Form::create(['name' => 'Request', 'fields' => [$this->field('text', 'Question')]]);

        $form->delete();

        $this->assertModelMissing($form);
    }

    public function test_each_field_gets_a_fixed_key_that_survives_renaming(): void
    {
        $form = Form::create(['name' => 'Request', 'fields' => [
            $this->field('text', 'Arrival time'),
            $this->field('text', 'Arrival time'),
            $this->field('text', 'Name'),
        ]]);

        $keys = array_column($form->fields, 'key');
        $this->assertSame(['arrival_time', 'arrival_time_2', 'name_2'], $keys);

        $fields = $form->fields;
        $fields[0]['label']['en'] = 'Expected time of arrival';
        $form->update(['fields' => $fields]);

        $this->assertSame($keys, array_column($form->refresh()->fields, 'key'));
    }

    public function test_the_contact_form_with_own_fields_is_stored_and_mailed(): void
    {
        $form = Form::fixed(Form::CONTACT);
        $form->update(['recipients' => 'a@kustverhuur.test, b@kustverhuur.test', 'fields' => [
            $this->field('select', 'Subject', ['required' => true, 'options' => ['en' => "Booking\nOther", 'nl' => "Boeking\nAnders"]]),
        ]]);

        $this->get('/contact')->assertOk()->assertSee('Subject')->assertSee('name="answers[subject]"', false);

        $this->post('/contact', ['name' => 'Jan', 'email' => 'jan@example.com', 'message' => 'Hallo'])
            ->assertSessionHasErrors('answers.subject');

        $this->post('/contact', ['name' => 'Jan', 'email' => 'jan@example.com', 'message' => 'Hallo', 'answers' => ['subject' => '1']])
            ->assertSessionHas('contact_sent');

        $submission = FormSubmission::sole();
        $this->assertSame($form->id, $submission->form_id);
        $this->assertSame(['name', 'email', 'message', 'subject'], array_column($submission->data, 'key'));
        $this->assertSame('Other', $submission->data[3]['value']);
        $this->assertSame('jan@example.com', $submission->replyAddress());

        Mail::assertSent(FormSubmissionMail::class, fn (FormSubmissionMail $mail) => $mail->hasTo('a@kustverhuur.test')
            && $mail->hasTo('b@kustverhuur.test')
            && $mail->hasReplyTo('jan@example.com'));
    }

    public function test_without_recipients_the_company_address_gets_the_mail(): void
    {
        $this->post('/contact', ['name' => 'Jan', 'email' => 'jan@example.com', 'message' => 'Hallo']);

        Mail::assertSent(FormSubmissionMail::class, fn (FormSubmissionMail $mail) => $mail->hasTo('info@kustverhuur.test'));
    }

    public function test_a_bot_that_fills_the_honeypot_sends_nothing(): void
    {
        $this->post('/contact', ['name' => 'Bot', 'email' => 'bot@example.com', 'message' => 'Spam', 'website' => 'x'])
            ->assertSessionHas('contact_sent');

        $this->assertSame(0, FormSubmission::query()->count());
        Mail::assertNothingSent();
    }

    /** An own form with every kind of check, on a published page. */
    private function formOnPage(array $fields, array $extra = []): array
    {
        $form = Form::create(['name' => 'Request', 'fields' => $fields, ...$extra]);
        $page = Page::create(['title' => 'Groups', 'slug' => 'groups', 'content' => '<p>Ask us</p>', 'published' => true, 'form_id' => $form->id]);

        return [$form->refresh(), $page];
    }

    public function test_an_own_form_is_shown_below_its_page_and_stored(): void
    {
        [$form, $page] = $this->formOnPage([
            $this->field('heading', 'About your group'),
            $this->field('number', 'Group size', ['required' => true]),
            $this->field('email', 'Your email', ['required' => true]),
        ], ['title' => 'Request a group stay']);

        $this->get('/groups')->assertOk()
            ->assertSee('Request a group stay')
            ->assertSee('About your group')
            ->assertSee(route('forms.store', $form), false);

        // Numbers above 1000 are fine (devsite rejected them).
        $this->from('/groups')->post(route('forms.store', $form), ['answers' => ['group_size' => '1500', 'your_email' => 'groep@example.com']])
            ->assertRedirect('/groups#form-'.$form->id)
            ->assertSessionHas('form_sent', $form->id);

        $submission = FormSubmission::sole();
        $this->assertSame(['group_size', 'your_email'], array_column($submission->data, 'key'));
        $this->assertSame('1500', $submission->data[0]['value']);
        Mail::assertSent(FormSubmissionMail::class, fn (FormSubmissionMail $mail) => $mail->hasReplyTo('groep@example.com'));
    }

    public function test_choices_and_agreement_are_checked_on_the_server(): void
    {
        [$form] = $this->formOnPage([
            $this->field('radio', 'Room', ['required' => true, 'options' => ['en' => "Double\nTwin"]]),
            $this->field('checkboxes', 'Extras', ['options' => ['en' => "Breakfast\nParking"]]),
            $this->field('checkbox', 'I agree to the privacy statement', ['required' => true]),
        ]);
        [$room, $extras, $agree] = array_column($form->fields, 'key');
        $url = route('forms.store', $form);
        $valid = [$room => '1', $extras => ['0', '1'], $agree => '1'];

        $this->post($url, ['answers' => [...$valid, $room => '7']])->assertSessionHasErrors("answers.{$room}");
        $this->post($url, ['answers' => [...$valid, $extras => ['5']]])->assertSessionHasErrors("answers.{$extras}.0");
        // The hidden "0" of an unticked agreement box is not enough.
        $this->post($url, ['answers' => [...$valid, $agree => '0']])->assertSessionHasErrors("answers.{$agree}");
        $this->assertSame(0, FormSubmission::query()->count());

        $this->post($url, ['answers' => $valid])->assertSessionHasNoErrors();
        $answers = FormSubmission::sole()->data;
        $this->assertSame('Twin', $answers[0]['value']);
        $this->assertSame('Breakfast, Parking', $answers[1]['value']);
        $this->assertTrue($answers[2]['value']);
    }

    public function test_an_inactive_form_and_the_fixed_forms_cannot_be_sent_this_way(): void
    {
        [$form] = $this->formOnPage([$this->field('text', 'Question')], ['active' => false]);

        $this->get('/groups')->assertOk()->assertDontSee(route('forms.store', $form), false);
        $this->post(route('forms.store', $form), ['answers' => ['question' => 'Hi']])->assertNotFound();
        $this->post(route('forms.store', Form::fixed(Form::CONTACT)), ['name' => 'Jan'])->assertNotFound();
        $this->assertSame(0, FormSubmission::query()->count());
    }

    public function test_the_submission_mail_is_in_the_admin_language_and_plain_text(): void
    {
        $form = Form::fixed(Form::CONTACT);
        $submission = $form->submissions()->create(['data' => [
            ['key' => 'name', 'type' => 'text', 'label' => 'Name', 'value' => '**Jan** <script>x</script>', 'fixed' => true],
        ]]);

        $html = (new FormSubmissionMail($submission))->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<strong>Jan</strong>', $html);
    }

    private function bookingPost(Property $property, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post("/{$property->slug}/book", [
            'arrival' => '2026-03-02', 'departure' => '2026-03-05', 'guests' => 2,
            'name' => 'Jan Jansen', 'email' => 'jan@example.com', 'terms' => '1', ...$extra,
        ]);
    }

    private function property(): Property
    {
        $this->app->instance(PaymentProvider::class, new FakeProvider);
        Setting::set('payments', 'provider', 'fake');

        return Property::create([
            'name' => 'Duinhuisje', 'slug' => 'duinhuisje', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 12000,
        ]);
    }

    public function test_own_questions_on_the_booking_form_go_with_the_booking_and_into_the_mails(): void
    {
        Form::fixed(Form::BOOKING)->update(['fields' => [
            $this->field('select', 'Reason for travel', ['required' => true, 'options' => ['en' => "Holiday\nBusiness", 'nl' => "Vakantie\nZakelijk"]]),
            $this->field('text', 'Expected arrival time'),
        ]]);
        $property = $this->property();

        $this->get("/{$property->slug}/book?arrival=2026-03-02&departure=2026-03-05&guests=2")
            ->assertOk()->assertSee('Reason for travel')->assertSee('Expected arrival time');

        $this->bookingPost($property)->assertSessionHasErrors('answers.reason_for_travel');
        $this->assertSame(0, Booking::query()->count());

        $this->bookingPost($property, ['answers' => ['reason_for_travel' => '1', 'expected_arrival_time' => '18:00']]);

        $booking = Booking::sole();
        $this->assertSame(['reason_for_travel', 'expected_arrival_time'], array_column($booking->form_answers, 'key'));

        // As merge fields in the guest's mails, in the guest's language.
        $this->assertSame('Reason for travel', MailPlaceholders::labels()[MailPlaceholders::ANSWER_PREFIX.'reason_for_travel']);
        $booking->update(['locale' => 'nl', 'status' => BookingStatus::Confirmed]);
        $property->update(['mail_texts' => ['confirmation' => ['nl' => '<p>Reden: <span data-type="mergeTag" data-id="answer_reason_for_travel"></span>, aankomst <span data-type="mergeTag" data-id="answer_expected_arrival_time"></span>.</p>']]]);

        $text = preg_replace('/\s+/', ' ', strip_tags((new BookingMail($booking->refresh(), MailType::Confirmation))->render()));
        $this->assertStringContainsString('Reden: Zakelijk, aankomst 18:00.', $text);
    }

    public function test_the_answers_are_shown_with_the_booking_in_the_admin(): void
    {
        Form::fixed(Form::BOOKING)->update(['fields' => [
            $this->field('radio', 'Reason for travel', ['options' => ['en' => "Holiday\nBusiness", 'nl' => "Vakantie\nZakelijk"]]),
        ]]);
        $property = $this->property();
        $this->bookingPost($property, ['answers' => ['reason_for_travel' => '0']]);

        $owner = $this->owner();
        $owner->update(['locale' => 'nl']);

        $this->actingAs($owner)
            ->get(\App\Filament\Resources\Bookings\BookingResource::getUrl('edit', ['record' => Booking::sole()]))
            ->assertOk()
            ->assertSee(__('Answers on the booking form', [], 'nl'))
            ->assertSee('Reason for travel')
            ->assertSee('Vakantie');
    }

    public function test_the_owner_can_hide_the_address_fields_of_the_booking_form(): void
    {
        Form::fixed(Form::BOOKING)->update(['fixed_fields' => ['address' => 'hidden', 'city' => 'hidden', 'name' => 'hidden', 'phone' => 'gone']]);
        $property = $this->property();

        $page = $this->get("/{$property->slug}/book?arrival=2026-03-02&departure=2026-03-05&guests=2")->assertOk();

        $page->assertDontSee('name="address"', false)
            ->assertDontSee('name="city"', false)
            ->assertSee('name="postal_code"', false)
            // Name cannot be hidden.
            ->assertSee('name="name"', false);
        // Only fields that may be changed, and only a known choice.
        $this->assertSame(['address' => 'hidden', 'city' => 'hidden'], Form::fixed(Form::BOOKING)->fixed_fields);
    }

    public function test_the_owner_chooses_per_fixed_field_required_optional_or_hidden(): void
    {
        Form::fixed(Form::BOOKING)->update(['fixed_fields' => ['phone' => 'required', 'address' => 'required', 'notes' => 'hidden']]);
        $property = $this->property();

        $this->get("/{$property->slug}/book?arrival=2026-03-02&departure=2026-03-05&guests=2")->assertOk()
            ->assertSee(__('Phone').' *')
            ->assertSee(__('Address').' *')
            ->assertSee('name="city"', false)
            ->assertDontSee(__('City').' *')
            ->assertDontSee('name="notes"', false);

        // Required: without it no booking.
        $this->bookingPost($property)->assertSessionHasErrors(['phone', 'address']);
        $this->bookingPost($property, ['phone' => 'none', 'address' => 'Dorpsstraat 1'])
            ->assertSessionHasErrors(['phone' => __('Enter a valid phone number.')]);
        $this->assertSame(0, Booking::count());

        // Hidden: what is sent anyway is not stored.
        $this->bookingPost($property, ['phone' => '06 1234 5678', 'address' => 'Dorpsstraat 1', 'notes' => 'Sent anyway'])
            ->assertSessionHasNoErrors();
        $booking = Booking::sole();
        $this->assertSame('06 1234 5678', $booking->guest_phone);
        $this->assertSame('Dorpsstraat 1', $booking->guest_address);
        $this->assertNull($booking->notes);
    }

    public function test_the_owner_sets_the_fixed_fields_in_the_admin(): void
    {
        $form = Form::fixed(Form::BOOKING);

        $page = Livewire::actingAs($this->owner())
            ->test(EditForm::class, ['record' => $form->getKey()])
            // Not set yet: the default.
            ->assertSchemaStateSet(['fixed_fields.phone' => 'optional', 'fixed_fields.address' => 'optional'])
            // Name, email address and the terms always stay required.
            ->assertFormFieldDoesNotExist('fixed_fields.name')
            ->assertFormFieldDoesNotExist('fixed_fields.email')
            ->assertFormFieldDoesNotExist('fixed_fields.terms');

        $page->fillForm(['fixed_fields.phone' => 'required', 'fixed_fields.notes' => 'hidden'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('required', $form->refresh()->mode('phone'));
        $this->assertSame('hidden', $form->mode('notes'));
        $this->assertSame('optional', $form->mode('city'));
        $this->assertSame('required', $form->mode('name'));

        // The contact form has no choices.
        Livewire::actingAs($this->owner())
            ->test(EditForm::class, ['record' => Form::fixed(Form::CONTACT)->getKey()])
            ->assertFormFieldHidden('fixed_fields.phone');
    }

    public function test_the_owner_builds_a_form_in_the_admin(): void
    {
        Livewire::actingAs($this->owner())
            ->test(CreateForm::class)
            ->fillForm([
                'name' => 'Group request',
                'fields' => [['type' => 'text', 'label' => ['en' => 'Group name', 'nl' => 'Groepsnaam'], 'required' => true, 'width' => 'half']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $form = Form::query()->where('name', 'Group request')->sole();
        $this->assertSame('group_name', $form->fields[0]['key']);
        $this->assertSame('Groepsnaam', $form->fields[0]['label']['nl']);
    }

    public function test_a_field_keeps_its_key_when_renamed_right_after_saving(): void
    {
        $form = Form::create(['name' => 'Request']);

        $page = Livewire::actingAs($this->owner())
            ->test(EditForm::class, ['record' => $form->getKey()])
            ->set('data.fields', ['new' => ['key' => null, 'type' => 'text', 'width' => 'full', 'required' => false, 'label' => ['en' => 'Group name']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $item = array_key_first($page->get('data.fields'));
        $page->set("data.fields.{$item}.label.en", 'Name of the group')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('group_name', $form->refresh()->fields[0]['key']);
        $this->assertSame('Name of the group', $form->fields[0]['label']['en']);
    }

    public function test_the_fixed_forms_have_no_delete_button(): void
    {
        Livewire::actingAs($this->owner())
            ->test(EditForm::class, ['record' => Form::fixed(Form::CONTACT)->getKey()])
            ->assertActionHidden('delete');

        $own = Form::create(['name' => 'Request']);
        Livewire::actingAs($this->owner())
            ->test(EditForm::class, ['record' => $own->getKey()])
            ->assertActionVisible('delete');
    }

    public function test_forms_and_submissions_need_their_own_permission(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'permissions' => ['bookings' => Access::ACTIONS]]);
        $this->actingAs($staff);

        $this->assertFalse(FormResource::canViewAny());
        $this->assertFalse(FormSubmissionResource::canViewAny());

        $staff->update(['permissions' => ['forms' => Access::ACTIONS, 'form_submissions' => [Access::VIEW]]]);
        $this->actingAs($staff->refresh());

        $this->assertTrue(FormResource::canViewAny());
        $this->assertTrue(FormSubmissionResource::canViewAny());
    }

    public function test_unhandled_submissions_are_counted_in_the_menu(): void
    {
        $form = Form::fixed(Form::CONTACT);
        $form->submissions()->create(['data' => []]);
        $form->submissions()->create(['data' => [], 'handled' => true]);

        $this->assertSame('1', FormSubmissionResource::getNavigationBadge());

        $this->actingAs($this->owner())
            ->get(FormSubmissionResource::getUrl('index'))
            ->assertOk();
    }
}
