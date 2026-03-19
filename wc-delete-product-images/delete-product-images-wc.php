<?php
/*
Plugin Name: Delete product images for WooCommerce
Plugin URI: https://uprise.ro
Description: Removes product assigned images (featured and gallery only) on product delete.
Requires at least: 4.7
Tested up to: 6.5.2
Stable tag: trunk
Requires PHP: 7.4
Version: 2.1
Author: Eduard V. Doloc
Author Email: eduard@uprise.ro
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'UPRISE_WC_DELETE_IMAGES_VERSION', '2.0' );
define( 'UPRISE_WC_DELETE_IMAGES_MIN_WC_VERSION', '3.0.0' );

// Check if WooCommerce is active
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

    // Initialize the plugin
    add_action( 'plugins_loaded', 'uprise_wc_delete_images_init' );

    /**
     * Get WooCommerce logger instance
     *
     * @return WC_Logger
     */
    function uprise_get_wc_logger() {
        static $logger = null;

        if ( $logger === null ) {
            $logger = wc_get_logger();
        }

        return $logger;
    }

    /**
     * Log message to WooCommerce logs
     *
     * @param string $message Message to log
     * @param string $level One of: emergency, alert, critical, error, warning, notice, info, debug
     *
     * @return void
     */
    function uprise_wc_log( $message, $level = 'error' ) {
        $logger  = uprise_get_wc_logger();
        $context = array( 'source' => 'uprise-delete-product-images' );
        $logger->log( $level, $message, $context );
    }

    /**
     * Initialize plugin functionality
     *
     * @return void
     */
    function uprise_wc_delete_images_init() {
        // Check WooCommerce version
        if ( version_compare( WC_VERSION, UPRISE_WC_DELETE_IMAGES_MIN_WC_VERSION, '<' ) ) {
            add_action( 'admin_notices', 'uprise_wc_delete_images_wc_version_notice' );

            return;
        }

        // Hook into product deletion
        add_action( 'delete_post', 'uprise_wc_delete_product_images', 10, 1 );
    }

    /**
     * Display admin notice for minimum WooCommerce version
     *
     * @return void
     */
    function uprise_wc_delete_images_wc_version_notice() {
        ?>
        <div class="error">
            <p><?php echo esc_html( sprintf(
                        'Delete Product Images for WooCommerce requires WooCommerce %s or higher. Please update WooCommerce to use this plugin.',
                        esc_html( UPRISE_WC_DELETE_IMAGES_MIN_WC_VERSION )
                ) ); ?></p>
        </div>
        <?php
    }

    /**
     * Deletes product featured and gallery images when a product is deleted.
     *
     * @param int $post_id The ID of the product being deleted.
     *
     * @return void
     */
    function uprise_wc_delete_product_images( $post_id ) {
        try {
            // Verify it's a product
            if ( get_post_type( $post_id ) !== 'product' ) {
                return;
            }

            // Verify nonce if triggered via admin action
            $nonce = isset( $_REQUEST['_wpnonce'] ) ? wp_unslash( sanitize_text_field( $_REQUEST['_wpnonce'] ) ) : '';
            if ( ! empty( $nonce ) && ! wp_verify_nonce( $nonce, 'delete-post_' . $post_id ) ) {
                return;
            }

            // Get product object
            $product = wc_get_product( $post_id );

            // Failsafe check
            if ( ! $product ) {
                uprise_wc_log( sprintf( 'Failed to get product with ID: %d', absint( $post_id ) ) );

                return;
            }

            // Collect all image IDs (featured, gallery, variations)
            $featured_image_id   = $product->get_image_id();
            $image_galleries_id  = $product->get_gallery_image_ids();
            $variation_image_ids = [];

            if ( $product->is_type( 'variable' ) ) {
                foreach ( $product->get_children() as $variation_id ) {
                    $variation = wc_get_product( $variation_id );
                    if ( $variation ) {
                        $vid = $variation->get_image_id();
                        if ( $vid ) {
                            $variation_image_ids[] = $vid;
                        }
                    }
                }
            }

            $all_image_ids = array_unique( array_filter( array_merge(
                    [ $featured_image_id ],
                    $image_galleries_id,
                    $variation_image_ids
            ) ) );

            // Check if any image is used elsewhere
            foreach ( $all_image_ids as $image_id ) {
                global $wpdb;

                // Check featured image usage
                $used_as_thumb = $wpdb->get_var( $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta}
			         WHERE meta_key = '_thumbnail_id'
			         AND meta_value = %d
			         AND post_id != %d
			         LIMIT 1",
                        $image_id,
                        $post_id
                ) );

                // Check gallery usage
                $used_in_gallery = $wpdb->get_var( $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta}
			         WHERE meta_key = '_product_image_gallery'
			         AND meta_value LIKE %s
			         AND post_id != %d
			         LIMIT 1",
                        '%' . $wpdb->esc_like( $image_id ) . '%',
                        $post_id
                ) );

                if ( $used_as_thumb || $used_in_gallery ) {
                    set_transient(
                            'uprise_delete_images_notice_' . $post_id,
                            sprintf(
                                    'Product #%d skipped. Image #%d is used by another product (%d).',
                                    $post_id,
                                    $image_id,
                                    $used_as_thumb ?: $used_in_gallery
                            ),
                            30
                    );

                    uprise_wc_log(
                            sprintf(
                                    'Skipped deletion for product %d because image %d is used elsewhere (product %d)',
                                    $post_id,
                                    $image_id,
                                    $used_as_thumb ?: $used_in_gallery
                            ),
                            'warning'
                    );

                    return;
                }
            }

            // Delete all collected images safely
            foreach ( $all_image_ids as $image_id ) {
                if ( wp_attachment_is_image( $image_id ) ) {
                    $result = wp_delete_attachment( $image_id, true );

                    if ( is_wp_error( $result ) ) {
                        uprise_wc_log(
                                sprintf(
                                        'Failed to delete image (ID: %d) for product %d: %s',
                                        $image_id,
                                        $post_id,
                                        $result->get_error_message()
                                )
                        );
                    } else {
                        uprise_wc_log(
                                sprintf(
                                        'Deleted image (ID: %d) for product %d',
                                        $image_id,
                                        $post_id
                                ),
                                'info'
                        );
                    }
                }
            }

        } catch ( Exception $e ) {
            uprise_wc_log( sprintf( 'Error deleting product images for product %d: %s',
                    absint( $post_id ),
                    esc_html( $e->getMessage() )
            ) );

            return;
        }
    }

    add_action( 'admin_notices', function () {
        global $pagenow;

        if ( $pagenow !== 'edit.php' && $pagenow !== 'post.php' ) {
            return;
        }

        foreach ( $_GET as $key => $value ) {
            if ( strpos( $key, 'post' ) !== false ) {
                $post_id = intval( $value );
                $notice  = get_transient( 'uprise_delete_images_notice_' . $post_id );

                if ( $notice ) {
                    echo '<div class="notice notice-warning"><p>' . esc_html( $notice ) . '</p></div>';
                    delete_transient( 'uprise_delete_images_notice_' . $post_id );
                }
            }
        }
    } );
}