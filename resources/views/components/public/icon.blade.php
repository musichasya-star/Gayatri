@props(['name' => 'sparkles'])
<svg {{ $attributes->class(['gp-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($name)
@case('flower')<path d="M12 3c-3 3-4 6-2 10 1 2 2 3 2 3s2-2 3-5c1-3-1-6-3-8Z"/><path d="M4 7c0 5 3 9 8 10C7 17 3 14 2 11m18-4c0 5-3 9-8 10 5 0 9-3 10-6M4 19c5 3 11 3 16 0"/>@break
@case('heart')<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>@break
@case('baby')<circle cx="12" cy="12" r="8"/><path d="M12 4c0 3 4 3 4 0M8.5 10h.01M15.5 10h.01M9 15c2 2 4 2 6 0M4 11H2m20 0h-2"/>@break
@case('wash')<rect x="4" y="2" width="16" height="20" rx="3"/><circle cx="12" cy="14" r="5"/><path d="M7 6h2m7 0h.01M7 14c4-3 6 3 10 0"/>@break
@case('arrow')<path d="M4 12h16m-6-6 6 6-6 6"/>@break
@case('check')<path d="m5 12 4 4L19 6"/>@break
@case('calendar')<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 10h18m-13 5 3 3 5-5"/>@break
@case('chat')<path d="M21 11.5a9 9 0 0 1-9 9 10 10 0 0 1-4-.9l-5 1.4 1.4-4.7a9 9 0 1 1 16.6-4.8Z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/>@break
@case('pin')<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>@break
@case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
@case('close')<path d="m6 6 12 12M6 18 18 6"/>@break
@case('plus')<path d="M12 5v14M5 12h14"/>@break
@case('bag')<path d="M5 7h14l2 14H3L5 7Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/>@break
@default<path d="m12 2 2.5 7.5L22 12l-7.5 2.5L12 22l-2.5-7.5L2 12l7.5-2.5L12 2Z"/>
@endswitch
</svg>
