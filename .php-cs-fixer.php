<?php

use PhpCsFixer\Config;

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__.'/version/205');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'braces' => [
            'allow_single_line_closure' => false
        ],
        'cast_spaces' => [
            'space' => 'none'
        ],
        'fully_qualified_strict_types' => true,
        'single_quote' => true,
        'is_null' => true,
        'no_php4_constructor' => true,
        'lowercase_keywords' => true,
        'modernize_types_casting' => true,
        'native_function_casing' => true,
        'new_with_braces' => true,
        'single_blank_line_at_eof' => true,
        'blank_line_after_opening_tag' => false,
        'no_mixed_echo_print' => ['use' => 'echo'],
        'concat_space' => ['spacing' => 'one'],
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_empty_statement' => true,
        'elseif' => true,
        'encoding' => true,
        'no_spaces_after_function_name' => true,
        'function_declaration' => ['closure_function_spacing' => 'one'],
        'include' => true,
        'indentation_type' => true,
        'no_alias_functions' => true,
        'blank_line_after_namespace' => true,
        'line_ending' => true,
        'multiline_whitespace_before_semicolons' => false,
        'single_import_per_statement' => true,
        'no_leading_namespace_whitespace' => true,
        'no_blank_lines_after_class_opening' => true,
        'no_blank_lines_after_phpdoc' => true,
        'object_operator_without_whitespace' => true,
        'no_spaces_inside_parenthesis' => true,
        'binary_operator_spaces' => [
            'default' => 'align_single_space_minimal'
        ],
        'phpdoc_align' => [
            'tags' => ['param']
        ],
        'no_leading_import_slash' => true,
        'self_accessor' => true,
        'single_blank_line_before_namespace' => true,
        'single_line_after_imports' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'standardize_not_equals' => true,
        'ternary_operator_spaces' => true,
        'no_trailing_whitespace' => true,
        'trim_array_spaces' => true,
        'unary_operator_spaces' => true,
        'no_unused_imports' => true,
        'visibility_required' => true,
        'no_whitespace_in_blank_line' => true,
        'dir_constant' => true,
        'align_multiline_comment' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_before_statement' => true,
        'combine_consecutive_unsets' => true,
        'header_comment' => [
            'comment_type' => 'PHPDoc',
            'separate' => 'bottom',
            'header' => sprintf("@copyright %s WebStollen GmbH\n@link https://www.webstollen.de", date('Y'))
        ],
        'list_syntax' => ['syntax' => 'short'],
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'no_null_property_initialization' => true,
        'echo_tag_syntax' => ['format' => 'short'],
        'no_useless_else' => true,
        'no_useless_return' => true,
        'ordered_class_elements' => false,
        'ordered_imports' => true,
        'phpdoc_add_missing_param_annotation' => true,
        'phpdoc_order' => true,
        'phpdoc_types_order' => true,
        'semicolon_after_instruction' => true,
        'single_line_comment_style' => true,
    ])
    ->setFinder($finder);
