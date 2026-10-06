<?php

namespace App\Services\Challenges;

use App\Enums\ChallengeType;
use App\Enums\ContentStatus;
use App\Models\Challenge;
use App\Models\Exercise;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Crée le défi du jour : 3 exercices d'entraînement de difficultés croissantes,
 * différents de ceux des 7 derniers jours quand c'est possible.
 */
class DailyChallengeGenerator
{
    public const EXERCISES = 3;

    public const XP_MULTIPLIER = 2.0;

    public function ensureFor(?Carbon $day = null): ?Challenge
    {
        $day = ($day ?? today())->copy()->startOfDay();
        $slug = 'defi-du-jour-'.$day->toDateString();

        $existing = Challenge::where('slug', $slug)->first();

        if ($existing) {
            return $existing;
        }

        $recent = Challenge::query()
            ->where('type', ChallengeType::Daily)
            ->where('starts_at', '>=', $day->copy()->subDays(7))
            ->with('exercises:id')
            ->get()
            ->flatMap(fn (Challenge $challenge) => $challenge->exercises->pluck('id'));

        $exercises = $this->pick($recent->all());

        if ($exercises->isEmpty()) {
            return null;
        }

        $challenge = Challenge::firstOrCreate(['slug' => $slug], [
            'type' => ChallengeType::Daily,
            'title' => 'Défi du jour — '.$day->isoFormat('dddd D MMMM'),
            'description' => 'Trois exercices, des points doublés en XP. Revenez chaque jour pour entretenir votre série !',
            'starts_at' => $day,
            'ends_at' => $day->copy()->addDay(),
            'xp_multiplier' => self::XP_MULTIPLIER,
            'status' => ContentStatus::Published,
        ]);

        $challenge->exercises()->sync($exercises->values()->mapWithKeys(fn (Exercise $exercise, int $index) => [
            $exercise->id => ['points' => 50 * $exercise->difficulty + 50, 'position' => $index],
        ])->all());

        return $challenge;
    }

    /**
     * @param  list<int>  $exclude
     * @return Collection<int, Exercise>
     */
    private function pick(array $exclude)
    {
        $fresh = Exercise::query()->practice()->whereNotIn('exercises.id', $exclude)->inRandomOrder()->limit(self::EXERCISES)->get();

        if ($fresh->count() < self::EXERCISES) {
            $fresh = $fresh->concat(
                Exercise::query()->practice()->whereNotIn('exercises.id', $fresh->pluck('id'))->inRandomOrder()->limit(self::EXERCISES - $fresh->count())->get()
            );
        }

        return $fresh->sortBy('difficulty')->values();
    }
}
