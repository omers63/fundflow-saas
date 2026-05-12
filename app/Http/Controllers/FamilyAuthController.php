<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class FamilyAuthController extends Controller
{
    public function show(Family $family): View
    {
        return view('auth.family-login', compact('family'));
    }

    public function login(Request $request, Family $family): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('family_id', $family->id)->where('email', $credentials['email'])->first();

        if (!$user || !Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            return back()->withErrors(['email' => __('Invalid credentials for this family.')])->onlyInput('email');
        }

        $request->session()->regenerate();

        return $user->role === 'admin'
            ? redirect('/admin')
            : redirect('/member');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
