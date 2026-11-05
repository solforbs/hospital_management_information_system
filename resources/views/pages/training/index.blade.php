<?php

use App\Support\Training;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Help & training')] class extends Component {
    #[Computed]
    public function roles(): array
    {
        return Training::roles(Auth::user());
    }

    #[Computed]
    public function progress()
    {
        return Training::progress(Auth::user());
    }

    #[Computed]
    public function required(): array
    {
        return Training::requiredModules($this->roles);
    }

    #[Computed]
    public function optional(): array
    {
        return array_diff_key(Training::modules(), $this->required);
    }

    #[Computed]
    public function passedRequired(): int
    {
        return collect(array_keys($this->required))->filter(fn ($k) => $this->progress->get($k)['passed'] ?? false)->count();
    }
};
?>

@php
    $req = count($this->required);
    $done = $this->passedRequired;
    $pct = $req ? (int) round($done * 100 / $req) : 100;
@endphp

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="rounded-2xl p-6 text-white shadow-sm" style="background: linear-gradient(135deg, #4E7CFF, #7033FF);">
        <div class="flex flex-wrap items-center justify-between gap-6">
            <div class="max-w-2xl">
                <div class="text-xs font-semibold uppercase tracking-wider opacity-80">{{ __('Help & training') }}</div>
                <h1 class="mt-1 text-2xl font-bold">{{ __('Learn how HMIS works') }}</h1>
                <p class="mt-2 text-sm opacity-90">{{ __('Short lessons for your role, each ending with a quiz. Pass mark :pass%. You can retake a quiz as many times as you need. Your hospital administrator can see your progress.', ['pass' => \App\Support\Training::PASS_MARK]) }}</p>
            </div>
            <div class="min-w-56 rounded-xl bg-white/15 p-4">
                <div class="text-sm opacity-90">{{ __('Required training') }}</div>
                <div class="mt-1 text-3xl font-bold">{{ $done }} / {{ $req }}</div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-white/25">
                    <div class="h-full rounded-full bg-white" style="width: {{ $pct }}%"></div>
                </div>
                <div class="mt-1 text-xs opacity-90">{{ $pct === 100 ? __('All required modules passed') : __(':pct% complete', ['pct' => $pct]) }}</div>
            </div>
        </div>
    </div>

    @foreach ([[__('Required for your role'), $this->required, true], [__('Other modules'), $this->optional, false]] as [$heading, $modules, $isRequired])
        @if ($modules)
            <section class="grid gap-3">
                <flux:heading level="2">{{ $heading }}</flux:heading>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($modules as $key => $m)
                        @php
                            $p = $this->progress->get($key);
                        @endphp
                        <a href="{{ route('training.module', $key) }}" wire:navigate wire:key="mod-{{ $key }}"
                           class="group flex flex-col gap-3 rounded-xl border border-neutral-200 bg-white p-5 transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex size-11 items-center justify-center rounded-xl text-white" style="background: linear-gradient(135deg, #4E7CFF, #7033FF);">
                                    <flux:icon :name="$m['icon']" class="size-6" />
                                </div>
                                @if ($p['passed'] ?? false)
                                    <flux:badge color="green" icon="check-circle">{{ __('Passed :pct%', ['pct' => $p['percent']]) }}</flux:badge>
                                @elseif ($p)
                                    <flux:badge color="amber">{{ __('Best :pct% — retake', ['pct' => $p['percent']]) }}</flux:badge>
                                @elseif ($isRequired)
                                    <flux:badge color="red">{{ __('Required') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc">{{ __('Optional') }}</flux:badge>
                                @endif
                            </div>
                            <div>
                                <div class="font-semibold group-hover:text-[#4E7CFF]">{{ __($m['title']) }}</div>
                                <div class="mt-1 text-sm text-neutral-500">{{ __($m['summary']) }}</div>
                            </div>
                            <div class="mt-auto flex items-center gap-4 text-xs text-neutral-500">
                                <span class="inline-flex items-center gap-1"><flux:icon name="clock" class="size-4" /> {{ __(':m min', ['m' => $m['minutes']]) }}</span>
                                <span class="inline-flex items-center gap-1"><flux:icon name="question-mark-circle" class="size-4" /> {{ trans_choice(':n question|:n questions', count($m['questions']), ['n' => count($m['questions'])]) }}</span>
                                @if ($p['passed_at'] ?? null)
                                    <span>{{ __('Passed :date', ['date' => $p['passed_at']->format('d M Y')]) }}</span>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach
</div>
