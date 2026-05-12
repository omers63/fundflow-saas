<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Family;
use App\Support\SystemSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicPageController extends Controller
{
    public function home(): View
    {
        return view('public.home', [
            'settings' => SystemSettings::all(),
        ]);
    }

    public function switchLocale(string $locale): RedirectResponse
    {
        if (in_array($locale, ['en', 'ar'], true)) {
            session(['locale' => $locale]);
        }

        return back();
    }

    public function familyPage(Family $family): View
    {
        return view('public.family', compact('family'));
    }

    public function submitEnrollment(Request $request, Family $family): RedirectResponse
    {
        $payload = $request->validate([
            'applicant_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $enrollment = Enrollment::create([
            ...$payload,
            'family_id' => $family->id,
            'status' => 'pending',
        ]);

        $family->users()
            ->where('role', 'admin')
            ->get()
            ->each
            ->notify(new \App\Notifications\EnrollmentSubmittedNotification($enrollment));

        return back()->with('success', __('Enrollment submitted successfully.'));
    }
}
