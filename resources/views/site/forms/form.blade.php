{{-- An own form from the form builder below the content of a page. --}}
<section id="form-{{ $form->id }}" class="mt-12 border-t border-stone-200 pt-8">
    @if ($title = $form->localized('title'))
        <h2 class="text-2xl font-semibold text-stone-900">{{ $title }}</h2>
    @endif

    @if (session('form_sent') === $form->id)
        <div class="mt-6 rounded-lg bg-green-50 p-4 text-green-800">
            {{ $form->localized('success_message') ?: __('Thank you for your message! We will get back to you as soon as possible.') }}
        </div>
    @else
        <form action="{{ route('forms.store', $form) }}" method="post" class="mt-6 space-y-4">
            @csrf
            {{-- Honeypot against spam --}}
            <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off">

            @if ($errors->any())
                <div class="rounded-lg bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-disc pl-4">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @include('site.forms.fields', ['form' => $form])

            <button type="submit"
                    class="rounded-xl button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-6 py-3 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700">
                {{ $form->localized('submit_label') ?: __('Send') }}
            </button>
        </form>
    @endif
</section>
