<div @if ($active) wire:init="check(true)" wire:poll.{{ $pollInterval }}s="check" @endif></div>
