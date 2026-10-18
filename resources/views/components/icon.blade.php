{!! str_replace(
    '<svg ',
    '<svg '.$attributes->class(['size-5 shrink-0'])->merge(['aria-hidden' => 'true', 'focusable' => 'false', 'fill' => 'currentColor']).' ',
    $svg(),
) !!}
