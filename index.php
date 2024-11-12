<?php
/**
 * Plugin Name: Polylang Post Duplicator
 * Plugin URI: https://www.wpzhiku.com/
 * Description: A simple plugin to duplicate posts and their translations in Polylang. Maintains relationships between translations while creating copies.
 * Version: 1.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: polylang-post-duplicator
 * Domain Path: /languages
 *
 * @package Polylang_Post_Duplicator
 */

add_filter('pll_get_post_types', function ($post_types)
{
    $post_types[] = 'kadence_form';

    return $post_types;
});


/**
 * 增强版文章复制功能，支持所有Polylang语言
 *
 * @param int $original_id  原始文章ID
 * @param int $duplicate_id 复制后的文章ID
 */
add_action('mtphr_post_duplicator_created', function ($original_id, $duplicate_id)
{
    // 确保ID为整数
    $original_id  = absint($original_id);
    $duplicate_id = absint($duplicate_id);

    // 获取所有已启用的语言
    $languages = pll_languages_list(['fields' => 'slug']);

    if (empty($languages)) {
        error_log('Polylang: No languages configured');

        return;
    }

    // 获取原文章的语言
    $original_lang = pll_get_post_language($original_id, 'slug');

    if ( ! $original_lang) {
        error_log('Polylang: Could not determine original post language');

        return;
    }

    // 获取下一个可用的语言
    $available_langs = array_diff($languages, [$original_lang]);

    if (empty($available_langs)) {
        error_log('Polylang: No available target languages');

        return;
    }

    // 获取原文章的所有翻译
    $existing_translations = pll_get_post_translations($original_id);

    // 从可用语言中过滤掉已有翻译的语言
    $available_langs = array_diff($available_langs, array_keys($existing_translations));

    if (empty($available_langs)) {
        error_log('Polylang: All languages already have translations');

        return;
    }

    // 选择第一个可用的语言作为新文章的语言
    $new_lang = reset($available_langs);

    // 设置复制文章的语言
    pll_set_post_language($duplicate_id, $new_lang);

    // 准备翻译关系数组
    $translations              = $existing_translations;
    $translations[ $new_lang ] = $duplicate_id;

    // 保存翻译关系
    pll_save_post_translations($translations);

    // 处理分类目录
    apply_or_create_taxonomy_translations($original_id, $duplicate_id, 'category', $new_lang);

    // 处理标签
    apply_or_create_taxonomy_translations($original_id, $duplicate_id, 'post_tag', $new_lang);

    // 可选：添加管理员通知
    add_action('admin_notices', function () use ($new_lang)
    {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p>' . sprintf(__('Post has been duplicated and set to language: %s', 'your-text-domain'), $new_lang) . '</p>';
        echo '</div>';
    });

}, 10, 2);


/**
 * 应用或创建分类翻译
 *
 * @param int    $original_id  原始文章ID
 * @param int    $duplicate_id 复制后的文章ID
 * @param string $taxonomy     分类法名称
 * @param string $new_lang     目标语言
 */
function apply_or_create_taxonomy_translations($original_id, $duplicate_id, $taxonomy, $new_lang)
{
    // 获取原文章的分类项
    $original_terms = wp_get_object_terms($original_id, $taxonomy);

    if (is_wp_error($original_terms) || empty($original_terms)) {
        return;
    }

    $translated_term_ids = [];

    foreach ($original_terms as $original_term) {
        // 获取分类项的翻译
        $translated_term_id = pll_get_term($original_term->term_id, $new_lang);

        // 如果没有找到翻译,则创建新的翻译
        if ( ! $translated_term_id) {
            $translated_term_id = create_term_translation($original_term, $taxonomy, $new_lang);
        }

        if ($translated_term_id) {
            $translated_term_ids[] = $translated_term_id;
        }
    }

    // 应用翻译的分类
    if ( ! empty($translated_term_ids)) {
        wp_set_object_terms($duplicate_id, $translated_term_ids, $taxonomy);
    }
}

/**
 * 创建分类项的翻译
 *
 * @param WP_Term $original_term 原始分类项
 * @param string  $taxonomy      分类法名称
 * @param string  $new_lang      目标语言
 *
 * @return int|false 新创建的分类项ID,失败返回false
 */
function create_term_translation($original_term, $taxonomy, $new_lang)
{
    // 准备翻译后的分类项数据
    $term_data = [
        'name'        => $original_term->name . ' (' . $new_lang . ')', // 可以根据需要修改命名方式
        'slug'        => $original_term->slug . '-' . $new_lang,
        'description' => $original_term->description,
        'parent'      => 0, // 暂时设置为顶级分类
    ];

    // 如果原分类有父级,尝试找到或创建父级的翻译
    if ($original_term->parent) {
        $parent_term = get_term($original_term->parent, $taxonomy);
        if ( ! is_wp_error($parent_term)) {
            $translated_parent_id = pll_get_term($parent_term->term_id, $new_lang);
            if ( ! $translated_parent_id) {
                // 递归创建父级翻译
                $translated_parent_id = create_term_translation($parent_term, $taxonomy, $new_lang);
            }
            if ($translated_parent_id) {
                $term_data[ 'parent' ] = $translated_parent_id;
            }
        }
    }

    // 创建新的分类项
    $new_term = wp_insert_term(
        $term_data[ 'name' ],
        $taxonomy,
        [
            'slug'        => $term_data[ 'slug' ],
            'description' => $term_data[ 'description' ],
            'parent'      => $term_data[ 'parent' ],
        ]
    );

    if (is_wp_error($new_term)) {
        error_log(sprintf(
            'Polylang: Failed to create translation for %s term ID %d in language %s. Error: %s',
            $taxonomy,
            $original_term->term_id,
            $new_lang,
            $new_term->get_error_message()
        ));

        return false;
    }

    // 设置语言
    pll_set_term_language($new_term[ 'term_id' ], $new_lang);

    // 建立翻译关系
    $translations              = pll_get_term_translations($original_term->term_id);
    $translations[ $new_lang ] = $new_term[ 'term_id' ];
    pll_save_term_translations($translations);

    // 复制分类项的元数据
    $term_meta = get_term_meta($original_term->term_id);
    if ($term_meta) {
        foreach ($term_meta as $meta_key => $meta_values) {
            foreach ($meta_values as $meta_value) {
                add_term_meta($new_term[ 'term_id' ], $meta_key, $meta_value);
            }
        }
    }

    return $new_term[ 'term_id' ];
}