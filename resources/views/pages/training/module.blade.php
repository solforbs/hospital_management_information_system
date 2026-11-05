<?php

use App\Models\TrainingAttempt;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\TenantContext;
use App\Support\Training;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One training module: lessons (from docs/training) followed by a quiz.
 * Options are shuffled per attempt and graded on the server; correct
 * answers and explanations are only shown after submitting.
 */
new #[Title('Training')] class extends Component {
    #[Locked]
    public string $moduleKey = '';

    public int $step = 0;

    #[Locked]
    public string $seed = '';

    #[Locked]
    public array $order = [];

    public array $answers = [];

    #[Locked]
    public ?array $result = null;

    public function mount(string $module): void
    {
        abort_unless(Training::module($module), 404);
        $this->moduleKey = $module;
    }

    #[Computed]
    public function module(): array
    {
        return Training::module($this->moduleKey);
    }

    #[Computed]
    public function lessons(): array
    {
        return Training::lessons($this->moduleKey);
    }

    #[Computed]
    public function quiz(): array
    {
        return $this->seed !== '' ? Training::quiz($this->moduleKey, $this->seed)['questions'] : [];
    }

    #[Computed]
    public function progress(): ?array
    {
        return Training::progress(Auth::user())->get($this->moduleKey);
    }

    public function go(int $step): void
    {
        $this->step = max(0, min($step, count($this->lessons)));

        if ($this->step === count($this->lessons) && $this->seed === '') {
            $this->newAttempt();
        }
    }

    public function startQuiz(): void
    {
        $this->go(count($this->lessons));
    }

    public function retake(): void
    {
        $this->newAttempt();
    }

    protected function newAttempt(): void
    {
        $this->seed = Auth::id().'-'.Str::random(16);
        $this->order = Training::quiz($this->moduleKey, $this->seed)['order'];
        $this->answers = [];
        $this->result = null;
        unset($this->quiz);
    }

    public function submit(AuditLogger $audit): void
    {
        abort_if($this->seed === '' || $this->result !== null, 422);

        $count = count($this->module['questions']);
        $rules = [];
        for ($i = 0; $i < $count; $i++) {
            $rules["answers.$i"] = ['required', 'integer', 'min:0', 'max:9'];
        }
        $this->validate($rules, [], collect(range(0, $count - 1))->mapWithKeys(fn ($i) => ["answers.$i" => __('question :n', ['n' => $i + 1])])->all());

        $graded = Training::grade($this->moduleKey, $this->answers, $this->order);

        TrainingAttempt::create([
            'user_id' => Auth::id(),
            'tenant_id' => app(TenantContext::class)->id(),
            'module' => $this->moduleKey,
            'score' => $graded['score'],
            'total' => $graded['total'],
            'percent' => $graded['percent'],
            'passed' => $graded['passed'],
            'answers' => collect($graded['details'])->map(fn ($d) => ['chosen' => $d['chosen'], 'correct' => $d['correct']])->all(),
        ]);

        $audit->log('training.quiz_completed', null, ['module' => $this->moduleKey, 'percent' => $graded['percent'], 'passed' => $graded['passed']]);

        $this->result = $graded;
        unset($this->progress);

        $graded['passed']
            ? Flux::toast(variant: 'success', text: __('Well done — you passed with :pct%.', ['pct' => $graded['percent']]))
            : Flux::toast(variant: 'warning', text: __('You scored :pct%. Review the lessons and try again.', ['pct' => $graded['percent']]));
    }
};
?>

@php
    $lessonCount = count($this->lessons);
    $onQuiz = $step >= $lessonCount;
    $lesson = $onQuiz ? null : $this->lessons[$step];
@endphp

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <style>
        .lesson { line-height: 1.75; font-size: 15px; }
        .lesson p { margin: 0 0 .9rem; }
        .lesson h3 { font-size: 1.05rem; font-weight: 600; margin: 1.4rem 0 .5rem; }
        .lesson ul, .lesson ol { margin: 0 0 1rem 1.25rem; }
        .lesson ul { list-style: disc; } .lesson ol { list-style: decimal; }
        .lesson li { margin: .3rem 0; padding-left: .2rem; }
        .lesson strong { font-weight: 600; }
        .lesson code { font-size: .85em; padding: .1rem .35rem; border-radius: .35rem; background: rgba(78,124,255,.1); }
        .lesson blockquote { margin: 1rem 0; padding: .8rem 1rem; border-left: 4px solid #4E7CFF; border-radius: 0 .75rem .75rem 0; background: rgba(78,124,255,.07); }
        .lesson blockquote p { margin: 0; }
        .lesson table { width: 100%; border-collapse: collapse; margin: 0 0 1rem; font-size: 14px; }
        .lesson th, .lesson td { border-bottom: 1px solid rgba(120,120,140,.25); padding: .5rem .6rem; text-align: left; vertical-align: top; }
        .lesson th { font-weight: 600; background: rgba(78,124,255,.06); }
    </style>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:link :href="route('training.index')" wire:navigate class="text-sm">← {{ __('All training') }}</flux:link>
            <flux:heading level="1" class="mt-1">{{ __($this->module['title']) }}</flux:heading>
        </div>
        @if ($this->progress['passed'] ?? false)
            <flux:badge color="green" icon="check-circle" size="lg">{{ __('Passed :pct%', ['pct' => $this->progress['percent']]) }}</flux:badge>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
        {{-- Steps --}}
        <nav class="grid content-start gap-1 rounded-xl border border-neutral-200 p-2 dark:border-neutral-700" aria-label="{{ __('Lessons') }}">
            @foreach ($this->lessons as $i => $l)
                <button type="button" wire:click="go({{ $i }})" wire:key="step-{{ $i }}"
                        class="flex items-start gap-2.5 rounded-lg px-3 py-2 text-left text-sm transition {{ $step === $i ? 'bg-[#4E7CFF]/10 font-semibold text-[#4E7CFF]' : 'hover:bg-neutral-100 dark:hover:bg-neutral-800' }}">
                    <span class="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold {{ $i < $step || $onQuiz ? 'bg-[#4E7CFF] text-white' : 'bg-neutral-200 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-200' }}">{{ $i + 1 }}</span>
                    <span>{{ $l['title'] }}</span>
                </button>
            @endforeach
            <button type="button" wire:click="startQuiz"
                    class="mt-1 flex items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm transition {{ $onQuiz ? 'bg-[#7033FF]/10 font-semibold text-[#7033FF]' : 'hover:bg-neutral-100 dark:hover:bg-neutral-800' }}">
                <flux:icon name="academic-cap" class="size-5 shrink-0" />
                <span>{{ __('Quiz') }} · {{ trans_choice(':n question|:n questions', count($this->module['questions']), ['n' => count($this->module['questions'])]) }}</span>
            </button>
        </nav>

        <div class="min-w-0 rounded-xl border border-neutral-200 bg-white p-6 sm:p-8 dark:border-neutral-700 dark:bg-neutral-900">
            @if (! $onQuiz)
                <div class="text-xs font-semibold uppercase tracking-wider text-[#4E7CFF]">{{ __('Lesson :n of :t', ['n' => $step + 1, 't' => $lessonCount]) }}</div>
                <h2 class="mt-1 mb-4 text-xl font-bold">{{ $lesson['title'] }}</h2>
                @if (! empty($lesson['intro']))
                    <div class="lesson mb-4 text-neutral-500">{!! $lesson['intro'] !!}</div>
                @endif
                <article class="lesson" wire:key="lesson-{{ $step }}">{!! $lesson['html'] !!}</article>

                <div class="mt-8 flex items-center justify-between gap-3 border-t border-neutral-200 pt-5 dark:border-neutral-700">
                    @if ($step > 0)
                        <flux:button variant="ghost" icon="arrow-left" wire:click="go({{ $step - 1 }})">{{ __('Previous') }}</flux:button>
                    @else
                        <span></span>
                    @endif
                    @if ($step < $lessonCount - 1)
                        <flux:button variant="primary" icon-trailing="arrow-right" wire:click="go({{ $step + 1 }})">{{ __('Next lesson') }}</flux:button>
                    @else
                        <flux:button variant="primary" icon="academic-cap" wire:click="startQuiz">{{ __('Take the quiz') }}</flux:button>
                    @endif
                </div>
            @else
                <div class="text-xs font-semibold uppercase tracking-wider text-[#7033FF]">{{ __('Check your understanding') }}</div>
                <h2 class="mt-1 text-xl font-bold">{{ __('Quiz: :title', ['title' => __($this->module['title'])]) }}</h2>
                <p class="mt-1 mb-6 text-sm text-neutral-500">{{ __('Answer every question. Pass mark :pass%. The order of answers changes on each attempt.', ['pass' => \App\Support\Training::PASS_MARK]) }}</p>

                @if ($result)
                    <div class="mb-6 rounded-xl p-5 text-white" style="background: linear-gradient(135deg, {{ $result['passed'] ? '#10B981, #059669' : '#F59E0B, #F97316' }});">
                        <div class="text-3xl font-bold">{{ $result['percent'] }}%</div>
                        <div class="mt-1 font-semibold">{{ $result['passed'] ? __('Passed — :s of :t correct', ['s' => $result['score'], 't' => $result['total']]) : __('Not passed yet — :s of :t correct', ['s' => $result['score'], 't' => $result['total']]) }}</div>
                        <div class="mt-1 text-sm opacity-90">{{ $result['passed'] ? __('This module is recorded as complete on your training record.') : __('Read the explanations below, review the lessons, then retake the quiz.') }}</div>
                    </div>
                @endif

                <form wire:submit="submit" class="grid gap-5">
                    @foreach ($this->quiz as $i => $question)
                        @php
                            $detail = $result['details'][$i] ?? null;
                        @endphp
                        <fieldset wire:key="q-{{ $seed }}-{{ $i }}" class="rounded-xl border p-4 {{ $detail ? ($detail['correct'] ? 'border-green-300 bg-green-50/50 dark:border-green-800 dark:bg-green-950/20' : 'border-red-300 bg-red-50/50 dark:border-red-800 dark:bg-red-950/20') : 'border-neutral-200 dark:border-neutral-700' }}">
                            <legend class="px-1 text-sm font-semibold">{{ $i + 1 }}. {{ __($question['q']) }}</legend>
                            <div class="mt-2 grid gap-2">
                                @foreach ($question['options'] as $pos => $option)
                                    @php
                                        $original = $order[$i][$pos];
                                        $isAnswer = $detail && $original === $detail['answer'];
                                        $isChosen = $detail && $original === $detail['chosen'];
                                    @endphp
                                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2 text-sm {{ $isAnswer ? 'border-green-400 bg-green-100/70 dark:bg-green-900/30' : ($isChosen ? 'border-red-400 bg-red-100/70 dark:bg-red-900/30' : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800') }}">
                                        <input type="radio" wire:model="answers.{{ $i }}" value="{{ $pos }}" class="mt-1" @disabled($result)>
                                        <span class="flex-1">{{ __($option) }}</span>
                                        @if ($isAnswer)
                                            <flux:icon name="check-circle" class="size-5 text-green-600" />
                                        @elseif ($isChosen)
                                            <flux:icon name="x-circle" class="size-5 text-red-600" />
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                            @if ($detail)
                                <p class="mt-3 text-sm text-neutral-600 dark:text-neutral-300"><strong>{{ __('Why:') }}</strong> {{ __($this->module['questions'][$i]['why']) }}</p>
                            @endif
                            <flux:error name="answers.{{ $i }}" />
                        </fieldset>
                    @endforeach

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-5 dark:border-neutral-700">
                        <flux:button variant="ghost" icon="arrow-left" wire:click="go({{ $lessonCount - 1 }})">{{ __('Back to lessons') }}</flux:button>
                        @if ($result)
                            <div class="flex gap-2">
                                <flux:button icon="arrow-path" wire:click="retake">{{ __('Retake quiz') }}</flux:button>
                                <flux:button variant="primary" :href="route('training.index')" wire:navigate>{{ __('Back to training') }}</flux:button>
                            </div>
                        @else
                            <flux:button type="submit" variant="primary" icon="check">{{ __('Submit answers') }}</flux:button>
                        @endif
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
