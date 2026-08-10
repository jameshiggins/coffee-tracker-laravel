<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * MustVerifyEmail (Q15): a freshly-registered user gets an email-verification
 * link emailed immediately; until they click it, email-bound features
 * (Q14 restock alerts) skip them.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'google_id',
        'display_name',
        'avatar_url',
    ];

    /**
     * Generate a URL-safe, unique display_name from an arbitrary name.
     * Slugifies to the [A-Za-z0-9_-] charset the display_name column + the
     * registration validation both require, then appends "-<random>" until
     * it doesn't collide with the UNIQUE index. Shared by the email/password
     * register path AND the Google OAuth path so neither can write a raw,
     * non-unique value that violates the constraint (a 500 on the second
     * "John Smith").
     */
    public static function generateDisplayName(?string $name): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]/', '', strtolower(str_replace(' ', '-', (string) $name)));
        if ($base === null || $base === '') {
            $base = 'taster';
        }
        $base = substr($base, 0, 40); // leave room for the random suffix within max:50

        $candidate = $base;
        $tries = 0;
        while (static::where('display_name', $candidate)->exists()) {
            $candidate = $base . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
            if (++$tries > 5) {
                break;
            }
        }

        return $candidate;
    }

    /**
     * The user object every auth-ish response returns — register, login,
     * GET /me, and the account-settings mutations. ONE definition so the
     * payloads can never drift (they used to be duplicated between
     * AuthController and a /me route closure).
     *
     * `google_linked` lets the SPA hint Google-sign-in users toward the
     * forgot-password flow (their account was created with a random password
     * they never saw, so any "enter your current password" gate needs that
     * escape hatch spelled out).
     */
    public function toAuthPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'display_name' => $this->display_name,
            'avatar_url' => $this->avatar_url,
            'email_verified' => $this->hasVerifiedEmail(),
            'google_linked' => $this->google_id !== null,
        ];
    }

    public function tastings(): HasMany
    {
        return $this->hasMany(Tasting::class);
    }

    public function wishlist(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function favoriteRoasters(): HasMany
    {
        return $this->hasMany(RoasterFavorite::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];
}
