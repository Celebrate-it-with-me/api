<?php

namespace App\Support\TemplatePackage;

/**
 * The closed catalog of codes a validation report can carry (spec section 4.8, plus the codes added by the HTML/AST
 * decisions). `ReportCodesTest` scans the sources and fails when a code is used that is not listed here, so the admin
 * UI can translate every code it may ever receive.
 */
final class ReportCodes
{
    /** Errors block the install. */
    public const ERRORS = [
        // archive
        'not_a_zip', 'zip_encrypted', 'zip_symlink', 'zip_path_traversal', 'zip_absolute_path', 'zip_too_many_files',
        'zip_bomb', 'zip_nested', 'unexpected_root', 'unexpected_file', 'name_invalid', 'name_duplicate', 'ext_not_allowed',
        'mime_mismatch', 'file_too_large', 'package_too_large',
        // manifest and documents
        'manifest_missing', 'manifest_invalid', 'slug_invalid', 'slug_taken', 'version_invalid', 'version_not_greater',
        'visibility_changed', 'level_not_allowed', 'level2_slug_not_registered', 'engine_too_new', 'tokens_invalid',
        'color_invalid', 'font_missing_file', 'font_not_self_hosted', 'woff2_invalid', 'strings_invalid', 'editable_invalid',
        'editable_bind_unknown', 'variant_unknown', 'sections_invalid', 'license_missing', 'license_asset_missing',
        'license_asset_unknown', 'preview_missing', 'preview_size', 'third_party_marks_public',
        // images
        'image_decode', 'image_dimensions',
        // svg
        'svg_invalid', 'svg_doctype', 'svg_forbidden_element', 'svg_forbidden_attribute', 'svg_external_ref',
        // css
        'css_parse_error', 'css_backslash', 'css_import', 'css_forbidden_at_rule', 'css_forbidden_property',
        'css_selector_forbidden', 'css_url_external', 'css_inv_var', 'css_important', 'css_size',
        // html
        'html_malformed', 'html_forbidden_tag', 'html_attr_forbidden', 'html_attr_invalid', 'html_url_forbidden',
        'html_duplicate_id', 'html_hidden_system_block', 'html_too_big', 'html_too_deep', 'layout_outlet',
        'section_block_required', 'img_alt_missing',
        // blocks, variables, behaviors
        'block_unknown', 'block_scope', 'block_too_many', 'block_children', 'block_prop_invalid', 'var_invalid',
        'var_unknown', 'var_filter_unknown', 'behavior_unknown', 'behavior_scope', 'behavior_conflict',
        // wave 7 (guard hardening): containers of blocks, states, music, fast animations, declared contrast
        'block_container_property', 'block_container_too_small', 'state_selector_property', 'behavior_on_music',
        'animation_too_fast', 'low_contrast_declared',
    ];

    /** Warnings never block. */
    public const WARNINGS = [
        'unused_file', 'budget_exceeded', 'first_fold_budget', 'field_removed', 'variant_removed', 'ignored_file',
        'empty_css', 'third_party_marks_declared', 'css_costly_property', 'contrast_low', 'img_size_missing', 'heading_order',
    ];

    public static function isError(string $code): bool
    {
        return in_array($code, self::ERRORS, true);
    }

    public static function isWarning(string $code): bool
    {
        return in_array($code, self::WARNINGS, true);
    }
}
