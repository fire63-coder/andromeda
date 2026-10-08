<?php

namespace App\Models;

use App\Enums\AttemptStatus;
use App\Enums\UserRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * Valeurs par défaut en mémoire (identiques aux défauts SQL), pour un utilisateur
     * tout juste créé et pas encore relu depuis la base.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'student',
        'xp' => 0,
        'current_streak' => 0,
        'longest_streak' => 0,
        'leaderboard_visible' => true,
        'is_active' => true,
        'assignment_emails' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'preferred_dialect_id',
        'leaderboard_visible',
        'assignment_emails',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'last_activity_on' => 'date',
            'leaderboard_visible' => 'boolean',
            'is_active' => 'boolean',
            'assignment_emails' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function canAuthorContent(): bool
    {
        return $this->role->canAuthorContent();
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function preferredDialect(): BelongsTo
    {
        return $this->belongsTo(SqlDialect::class, 'preferred_dialect_id');
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withPivot(['role', 'joined_at']);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(UserSubmission::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(UserProgress::class);
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class)->withPivot(['awarded_at', 'context']);
    }

    public function xpTransactions(): HasMany
    {
        return $this->hasMany(XpTransaction::class);
    }

    /**
     * Tentative de certification en mode examen en cours (le reste de l'application est alors fermé).
     */
    public function activeSecureExam(): ?CertificationAttempt
    {
        return $this->certificationAttempts()
            ->where('status', AttemptStatus::InProgress)
            ->where('expires_at', '>', now())
            ->whereHas('certification', fn ($q) => $q->where('exam_mode', true))
            ->latest('id')
            ->first();
    }

    public function certificationAttempts(): HasMany
    {
        return $this->hasMany(CertificationAttempt::class);
    }

    public function challengeParticipations(): HasMany
    {
        return $this->hasMany(ChallengeParticipation::class);
    }
}
