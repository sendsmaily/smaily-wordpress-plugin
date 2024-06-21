<?php

namespace Smaily_WC;

/**
 * Class for preparing Woocommerce related data
 */
class Data_Prepare
{
    public static function prepare_form_data($payload)
    {
        // Collect and normalize form data.
        $abandoned_cart    = self::prepare_abandoned_cart_data($payload);
        $checkout_checkbox = self::prepare_checkout_checkbox_data($payload);
        $customer_sync     = self::prepare_customer_sync_data($payload);
        $rss               = self::prepare_rss_data($payload);

        // Validate abandoned cart data.
        if ($abandoned_cart['enabled'] === true) {
            // Ensure abandoned cart autoresponder is selected.
            if (empty($abandoned_cart['autoresponder'])) {
                echo wp_json_encode(
                    array(
                        'error' => __('Select autoresponder for abandoned cart!', 'smaily'),
                    )
                );
                wp_die();
            }

            // Ensure abandoned cart delay is valid.
            if ($abandoned_cart['delay'] < 10) {
                echo wp_json_encode(
                    array(
                        'error' => __('Abandoned cart cutoff time value must be 10 or higher!', 'smaily'),
                    )
                );
                wp_die();
            }
        }

        // Compile settings update values.
        $update_values = array(
            'customer_sync_enabled'     => (int) $customer_sync['enabled'],
            'syncronize_additional'     => $customer_sync['fields'],
            'enable_cart'               => (int) $abandoned_cart['enabled'],
            'checkout_checkbox_enabled' => (int) $checkout_checkbox['enabled'],
            'checkbox_auto_checked'     => (int) $checkout_checkbox['auto_check'],
            'checkbox_order'            => $checkout_checkbox['position'],
            'checkbox_location'         => $checkout_checkbox['location'],
            'rss_category'              => $rss['category'],
            'rss_limit'                 => $rss['limit'],
            'rss_order_by'              => $rss['sort_field'],
            'rss_order'                 => $rss['sort_order'],
        );

        if ($abandoned_cart['enabled'] === true) {
            $update_values = array_merge(
                $update_values,
                array(
                    'cart_autoresponder'    => '',
                    'cart_autoresponder_id' => $abandoned_cart['autoresponder'],
                    'cart_cutoff'           => $abandoned_cart['delay'],
                    'cart_options'          => $abandoned_cart['fields'],
                )
            );
        }

        return $update_values;
    }

    /**
     * Collect and normalize customer synchronization data.
     *
     * @param array $payload
     * @return array
     */
    private static function prepare_customer_sync_data(array $payload)
    {
        $customer_sync = array(
            'enabled' => false,
            'fields'  => array(),
        );

        if (isset($payload['customer_sync']) and is_array($payload['customer_sync'])) {
            $raw_customer_sync = $payload['customer_sync'];
            $allowed_fields    = array(
                'customer_group',
                'customer_id',
                'first_name',
                'first_registered',
                'last_name',
                'nickname',
                'site_title',
                'user_dob',
                'user_gender',
                'user_phone',
            );

            $customer_sync['enabled'] = isset($raw_customer_sync['enabled']) ? (bool) (int) $raw_customer_sync['enabled'] : $customer_sync['enabled'];
            $customer_sync['fields']  = isset($raw_customer_sync['fields']) ? array_values((array) $raw_customer_sync['fields']) : $customer_sync['fields'];

            // Ensure only allowed fields are selected.
            $customer_sync['fields'] = array_values(array_intersect($customer_sync['fields'], $allowed_fields));
        }

        return $customer_sync;
    }

    /**
     * Collect and normalize abandoned cart data.
     *
     * @param array $payload
     * @return array
     */
    private static function prepare_abandoned_cart_data(array $payload)
    {
        $abandoned_cart = array(
            'autoresponder' => 0,
            'delay'         => 10,  // In minutes.
            'enabled'       => false,
            'fields'        => array(),
        );

        if (isset($payload['abandoned_cart']) and is_array($payload['abandoned_cart'])) {
            $raw_abandoned_cart = $payload['abandoned_cart'];
            $allowed_fields     = array(
                'first_name',
                'last_name',
                'product_base_price',
                'product_description',
                'product_name',
                'product_price',
                'product_quantity',
                'product_sku',
                'product_images'
            );

            $abandoned_cart['autoresponder'] = isset($raw_abandoned_cart['autoresponder']) ? (int) $raw_abandoned_cart['autoresponder'] : $abandoned_cart['autoresponder'];
            $abandoned_cart['delay']         = isset($raw_abandoned_cart['delay']) ? (int) $raw_abandoned_cart['delay'] : $abandoned_cart['delay'];
            $abandoned_cart['enabled']       = isset($raw_abandoned_cart['enabled']) ? (bool) (int) $raw_abandoned_cart['enabled'] : $abandoned_cart['enabled'];
            $abandoned_cart['fields']        = isset($raw_abandoned_cart['fields']) ? array_values((array) $raw_abandoned_cart['fields']) : $abandoned_cart['fields'];

            // Ensure only allowed fields are selected.
            $abandoned_cart['fields'] = array_values(array_intersect($abandoned_cart['fields'], $allowed_fields));
        }

        return $abandoned_cart;
    }

    /**
     * Collect and normalize checkout checkbox data.
     *
     * @param array $payload
     * @return array
     */
    private static function prepare_checkout_checkbox_data(array $payload)
    {
        $checkout_checkbox = array(
            'auto_check' => false,
            'enabled'    => false,
            'location'   => 'checkout_billing_form',
            'position'   => 'after',
        );

        if (isset($payload['checkout_checkbox']) and is_array($payload['checkout_checkbox'])) {
            $raw_checkout_checkbox = $payload['checkout_checkbox'];
            $allowed_locations     = array(
                'checkout_billing_form',
                'checkout_registration_form',
                'checkout_shipping_form',
                'order_notes',
            );
            $allowed_positions     = array(
                'before',
                'after',
            );

            $checkout_checkbox['auto_check'] = isset($raw_checkout_checkbox['auto_check']) ? (bool) (int) $raw_checkout_checkbox['auto_check'] : $checkout_checkbox['auto_check'];
            $checkout_checkbox['enabled']    = isset($raw_checkout_checkbox['enabled']) ? (bool) (int) $raw_checkout_checkbox['enabled'] : $checkout_checkbox['enabled'];
            $checkout_checkbox['location']   = isset($raw_checkout_checkbox['location']) ? wp_unslash(sanitize_text_field($raw_checkout_checkbox['location'])) : $checkout_checkbox['location'];
            $checkout_checkbox['position']   = isset($raw_checkout_checkbox['position']) ? wp_unslash(sanitize_text_field($raw_checkout_checkbox['position'])) : $checkout_checkbox['position'];

            // Ensure only an allowed location is selected.
            if (!in_array($checkout_checkbox['location'], $allowed_locations, true)) {
                $checkout_checkbox['location'] = 'checkout_billing_form';
            }

            // Ensure only an allowed position is selected.
            if (!in_array($checkout_checkbox['position'], $allowed_positions, true)) {
                $checkout_checkbox['position'] = 'after';
            }
        }

        return $checkout_checkbox;
    }

    /**
     * Collect and normalize RSS data.
     *
     * @param array $payload
     * @return array
     */
    private static function prepare_rss_data(array $payload)
    {
        $rss = array(
            'category'   => '',
            'limit'      => 50,
            'sort_field' => 'modified',
            'sort_order' => 'DESC',
        );

        if (isset($payload['rss']) and is_array($payload['rss'])) {
            $raw_rss             = $payload['rss'];
            $allowed_sort_fields = array(
                'date',
                'id',
                'modified',
                'name',
                'rand',
                'type',
            );
            $allowed_sort_orders = array(
                'ASC',
                'DESC',
            );

            $rss['category']   = isset($raw_rss['category']) ? wp_unslash(sanitize_text_field($raw_rss['category'])) : $rss['category'];
            $rss['limit']      = isset($raw_rss['limit']) ? (int) $raw_rss['limit'] : $rss['limit'];
            $rss['sort_field'] = isset($raw_rss['sort_field']) ? wp_unslash(sanitize_text_field($raw_rss['sort_field'])) : $rss['sort_field'];
            $rss['sort_order'] = isset($raw_rss['sort_order']) ? wp_unslash(sanitize_text_field($raw_rss['sort_order'])) : $rss['sort_order'];

            // Ensure only an allowed sort field is selected.
            if (!in_array($rss['sort_field'], $allowed_sort_fields, true)) {
                $rss['sort_field'] = 'modified';
            }

            // Ensure only an allowed sort order is selected.
            if (!in_array($rss['sort_order'], $allowed_sort_orders, true)) {
                $rss['sort_order'] = 'DESC';
            }
        }

        return $rss;
    }
}
