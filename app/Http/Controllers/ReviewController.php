<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Review form for guests, reachable via the signed link in the
 * review request mail. One review per stay, only after departure.
 */
class ReviewController extends Controller
{
    public function create(Booking $booking): View
    {
        return view('site.reviews.create', [
            'booking' => $booking,
            'property' => $booking->property,
            'block' => $this->block($booking),
        ]);
    }

    public function store(Request $request, Booking $booking): RedirectResponse
    {
        abort_if($this->block($booking) !== null, 403);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:3000'],
        ]);

        $rating = (int) $validated['rating'];

        $booking->review()->create([
            'property_id' => $booking->property_id,
            'rating' => $rating,
            'comment' => $validated['comment'] ?? null,
            'guest_name' => $booking->guest_name,
            'status' => \App\Models\Review::statusForNewReview($rating),
        ]);

        return redirect()
            ->to($booking->signedLink('reviews.create'))
            ->with('review_sent', true);
    }

    /**
     * Reason why no review can be left (yet), or null.
     */
    private function block(Booking $booking): ?string
    {
        if ($booking->review()->exists()) {
            return __('You have already left a review for this stay — thank you!');
        }

        if ($booking->status === BookingStatus::Cancelled) {
            return __('A review cannot be left for a cancelled booking.');
        }

        if ($booking->departure_date->isFuture()) {
            return __('You can leave a review after your stay has ended.');
        }

        return null;
    }
}
