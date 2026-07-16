<?php

declare(strict_types=1);

/**
 * Deliberately just @PSR12, plus a handful of rules that remove ambiguity
 * rather than impose taste.
 *
 * The opinionated presets (@PhpCsFixer, @Symfony) were measured against this
 * codebase and would rewrite 53 of 58 files — Yoda conditions
 * (`null === $x`), space-less concatenation (`$hours.'H'`), and reordering
 * `string|null` to `null|string`. None of that changes behaviour, all of it
 * destroys git blame, and Yoda conditions are actively divisive. @PSR12 is a
 * no-op on the current tree, which is exactly what a style ratchet should be:
 * it catches future drift without churning the past.
 */
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->append([__DIR__ . '/bin/git2ics']);

return (new PhpCsFixer\Config())
    // The only risky rule enabled is declare_strict_types, and it is risky
    // solely because ADDING it to a file that lacked it changes type coercion.
    // Every file here already declares it, so the rule is a no-op that stops
    // a new file from being added without it.
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,

        'declare_strict_types' => true,

        // Unused imports are dead weight the analysers do not flag.
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],

        // Trailing commas keep diffs to one line when arguments are added.
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],

        'single_quote' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
