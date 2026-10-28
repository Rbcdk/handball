@props(['form' => []])
<span class="form" aria-label="Last {{ count($form) }} results: {{ implode(', ', array_map(fn ($r) => ['W' => 'win', 'D' => 'draw', 'L' => 'loss'][$r], $form)) }}">
    @foreach ($form as $r)<span class="{{ $r }}" aria-hidden="true">{{ $r }}</span>@endforeach
</span>
