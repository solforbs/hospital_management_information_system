<?php

namespace App\Support;

use App\Models\TrainingAttempt;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The training centre: modules, lessons (from docs/training/*.md), quizzes,
 * grading and per-user progress.
 */
class Training
{
    public const PASS_MARK = 80;

    protected static ?array $catalog = null;

    public static function modules(): array
    {
        return self::$catalog ??= require resource_path('training/catalog.php');
    }

    public static function module(string $key): ?array
    {
        return self::modules()[$key] ?? null;
    }

    /**
     * Lessons of a module: each "## " heading of its markdown file.
     *
     * @return list<array{title: string, html: string}>
     */
    public static function lessons(string $key): array
    {
        $module = self::module($key);
        $path = base_path('docs/training/'.$module['file']);
        $markdown = is_file($path) ? file_get_contents($path) : '';

        $parts = preg_split('/^## /m', $markdown);
        $intro = trim(preg_replace('/^# .*$/m', '', array_shift($parts) ?? ''));

        $lessons = [];
        foreach ($parts as $part) {
            [$title, $body] = array_pad(explode("\n", $part, 2), 2, '');
            $lessons[] = ['title' => trim($title), 'html' => self::render($body)];
        }

        if ($intro !== '' && $lessons) {
            $lessons[0]['intro'] = self::render($intro);
        }

        return $lessons;
    }

    public static function render(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /** Role names the user holds in the current hospital. */
    public static function roles(User $user): array
    {
        try {
            return $user->getRoleNames()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function isRequired(array $module, array $roles): bool
    {
        return in_array('*', $module['required_for'], true) || array_intersect($module['required_for'], $roles) !== [];
    }

    /** @return array<string, array> module key => module, required for these roles */
    public static function requiredModules(array $roles): array
    {
        return array_filter(self::modules(), fn ($m) => self::isRequired($m, $roles));
    }

    /**
     * Questions in a stable shuffled order for one attempt, without answers.
     *
     * @return array{order: array<int, array<int, int>>, questions: list<array{q: string, options: list<string>}>}
     */
    public static function quiz(string $key, string $seed): array
    {
        $order = [];
        $questions = [];

        foreach (self::module($key)['questions'] as $i => $question) {
            $indexes = array_keys($question['options']);
            mt_srand(crc32($seed.'|'.$key.'|'.$i));
            shuffle($indexes);
            mt_srand();

            $order[$i] = $indexes;
            $questions[] = ['q' => $question['q'], 'options' => array_map(fn ($idx) => $question['options'][$idx], $indexes)];
        }

        return ['order' => $order, 'questions' => $questions];
    }

    /**
     * Grade answers given as displayed positions, using that attempt's order.
     *
     * @param  array<int, int|string|null>  $positions  question index => chosen displayed position
     * @param  array<int, array<int, int>>  $order
     */
    public static function grade(string $key, array $positions, array $order): array
    {
        $details = [];
        $score = 0;

        foreach (self::module($key)['questions'] as $i => $question) {
            $position = $positions[$i] ?? null;
            $chosen = $position === null || $position === '' ? null : ($order[$i][(int) $position] ?? null);
            $correct = $chosen === $question['answer'];
            $score += $correct ? 1 : 0;

            $details[$i] = [
                'chosen' => $chosen,
                'correct' => $correct,
                'answer' => $question['answer'],
            ];
        }

        $total = count($details);
        $percent = $total ? (int) round($score * 100 / $total) : 0;

        return ['score' => $score, 'total' => $total, 'percent' => $percent, 'passed' => $percent >= self::PASS_MARK, 'details' => $details];
    }

    /**
     * Best result per module for a user.
     *
     * @return Collection<string, array{passed: bool, percent: int, attempts: int, last: mixed, passed_at: mixed}>
     */
    public static function progress(User $user): Collection
    {
        return TrainingAttempt::where('user_id', $user->id)->orderBy('created_at')->get()
            ->groupBy('module')
            ->map(fn ($attempts) => [
                'passed' => $attempts->contains('passed', true),
                'percent' => (int) $attempts->max('percent'),
                'attempts' => $attempts->count(),
                'last' => $attempts->last()->created_at,
                'passed_at' => $attempts->firstWhere('passed', true)?->created_at,
            ]);
    }
}
