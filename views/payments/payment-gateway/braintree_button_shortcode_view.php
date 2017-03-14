<?php

/* * ************************************************
 * Braintree Buy Now button shortcode handler
 * *********************************************** */
add_filter('swpm_payment_button_shortcode_for_braintree_buy_now', 'swpm_render_braintree_buy_now_button_sc_output', 10, 2);

function swpm_render_braintree_buy_now_button_sc_output($button_code, $args) {

    $button_id = isset($args['id']) ? $args['id'] : '';
    if (empty($button_id)) {
        return '<p class="swpm-red-box">Error! swpm_render_braintree_buy_now_button_sc_output() function requires the button ID value to be passed to it.</p>';
    }

    //Check new_window parameter
    $window_target = isset($args['new_window']) ? 'target="_blank"' : '';
    $button_text = (isset($args['button_text'])) ? $args['button_text'] : SwpmUtils::_('Buy Now');
    $billing_address = isset($args['billing_address']) ? '1' : '';
    ; //By default don't show the billing address in the checkout form.
    $item_logo = ''; //Can be used to show an item logo or thumbnail in the checkout form.

    $settings = SwpmSettings::get_instance();
    $button_cpt = get_post($button_id); //Retrieve the CPT for this button
    $item_name = htmlspecialchars($button_cpt->post_title);

    $membership_level_id = get_post_meta($button_id, 'membership_level_id', true);
    //Verify that this membership level exists (to prevent user paying for a level that has been deleted)
    if (!SwpmUtils::membership_level_id_exists($membership_level_id)) {
        return '<p class="swpm-red-box">Error! The membership level specified in this button does not exist. You may have deleted this membership level. Edit the button and use the correct membership level.</p>';
    }

    //Payment amount and currency
    $payment_amount = get_post_meta($button_id, 'payment_amount', true);
    if (!is_numeric($payment_amount)) {
        return '<p class="swpm-red-box">Error! The payment amount value of the button must be a numeric number. Example: 49.50 </p>';
    }
    $payment_amount = round($payment_amount, 2); //round the amount to 2 decimal place.
    $payment_amount_formatted = number_format($payment_amount, 2, '.', '');
    $payment_currency = get_post_meta($button_id, 'currency_code', true);

    //Return, cancel, notifiy URLs
    $return_url = get_post_meta($button_id, 'return_url', true);
    if (empty($return_url)) {
        $return_url = SIMPLE_WP_MEMBERSHIP_SITE_HOME_URL;
    }
    $notify_url = SIMPLE_WP_MEMBERSHIP_SITE_HOME_URL . '/?swpm_process_braintree_buy_now=1'; //We are going to use it to do post payment processing.
    //$button_image_url = get_post_meta($button_id, 'button_image_url', true);//Stripe doesn't currenty support button image for their standard checkout.
    //User's IP address
    $user_ip = SwpmUtils::get_user_ip_address();
    $_SESSION['swpm_payment_button_interaction'] = $user_ip;

    //Custom field data
    $custom_field_value = 'subsc_ref=' . $membership_level_id;
    $custom_field_value .= '&user_ip=' . $user_ip;
    if (SwpmMemberUtils::is_member_logged_in()) {
        $member_id = SwpmMemberUtils::get_logged_in_members_id();
        $custom_field_value .= '&swpm_id=' . $member_id;
        $member_first_name = SwpmMemberUtils::get_member_field_by_id($member_id, 'first_name');
        $member_last_name = SwpmMemberUtils::get_member_field_by_id($member_id, 'last_name');
        $member_email = SwpmMemberUtils::get_member_field_by_id($member_id, 'email');
    }
    $custom_field_value = apply_filters('swpm_custom_field_value_filter', $custom_field_value);

    //Sandbox settings
    $sandbox_enabled = $settings->get_value('enable-sandbox-testing');

    if ($sandbox_enabled) {
        $braintree_env = "sandbox";
    } else {
        $braintree_env = "production";
    }

    require_once(SIMPLE_WP_MEMBERSHIP_PATH . 'lib/braintree/lib/autoload.php');

    try {
        Braintree_Configuration::environment($braintree_env);
        Braintree_Configuration::merchantId(get_post_meta($button_id, 'braintree_merchant_acc_id', true));
        Braintree_Configuration::publicKey(get_post_meta($button_id, 'braintree_public_key', true));
        Braintree_Configuration::privateKey(get_post_meta($button_id, 'braintree_private_key', true));
        $clientToken = Braintree_ClientToken::generate();
    } catch (Exception $e) {
        $e_class = get_class($e);
        $ret = 'Braintree Pay Now button error: ' . $e_class;
        if ($e_class == "Braintree\Exception\Authentication")
            $ret.="<br />API keys are incorrect. Double-check that you haven't accidentally tried to use your sandbox keys in production or vice-versa.";
        return $ret;
    }

    $uniqid = uniqid(); // Get unique ID to ensure several buttons can be added to one page without conflicts

    /* === Braintree Buy Now Button Form === */
    $output = '';
    $output .= '<div class="swpm-button-wrapper swpm-braintree-buy-now-wrapper">';
    $output .= "<form action='" . $notify_url . "' METHOD='POST'> ";
    $output.='<div id="swpm-form-cont-' . $uniqid . '" class="swpm-braintree-form-container swpm-form-container-' . $button_id . '" style="display:none;"></div>';
    $output.='<div id="swpm-braintree-additional-fields-container-' . $uniqid . '" class="swpm-braintree-additional-fields-container swpm-braintree-additional-fields-container-' . $button_id . '" style="display:none;">';
    $output.='<p><input type="text" name="first_name" placeholder="First Name" value="' . (isset($member_first_name) ? $member_first_name : '') . '" required></p>';
    $output.='<p><input type="text" name="last_name" placeholder="Last Name" value="' . (isset($member_last_name) ? $member_last_name : '') . '" required></p>';
    $output.='<p><input type="text" name="member_email" placeholder="Email" value="' . (isset($member_email) ? $member_email : '') . '" required></p>';
    $output.='<div id="swpm-braintree-amount-container-' . $uniqid . '" class="swpm-braintree-amount-container"><p>' . $payment_amount_formatted . ' ' . $payment_currency . '</p></div>';
    $output.='</div>';
    $output.='<button id="swpm-show-form-btn-' . $uniqid . '" class="swpm-braintree-pay-now-button swpm-braintree-show-form-button-' . $button_id . '" type="button" onclick="swpm_braintree_show_form_' . $uniqid . '();">' . $button_text . '</button>';
    $output.='<button id="swpm-submit-form-btn-' . $uniqid . '" class="swpm-braintree-pay-now-button swpm-braintree-submit-form-button-' . $button_id . '" type="submit" style="display: none;">' . $button_text . '</button>';
    $output.='<script src="https://js.braintreegateway.com/js/braintree-2.30.0.min.js"></script>';
    $output.='<script>';
    $output .= "function swpm_braintree_show_form_" . $uniqid . "() {";
    $output .= 'document.getElementById(\'swpm-show-form-btn-' . $uniqid . '\').style.display = "none";';
    $output .= 'document.getElementById(\'swpm-submit-form-btn-' . $uniqid . '\').style.display = "block";';
    $output .= 'document.getElementById(\'swpm-form-cont-' . $uniqid . '\').style.display = "block";';
    $output.="braintree.setup('" . $clientToken . "', 'dropin', {container: 'swpm-form-cont-" . $uniqid . "', ";
    $output.="onReady: function(obj){document.getElementById('swpm-braintree-additional-fields-container-" . $uniqid . "').style.display = \"block\";}});";
    $output .= '}';
    $output.='</script>';

    $output .= wp_nonce_field('stripe_payments', '_wpnonce', true, false);
    $output .= '<input type="hidden" name="item_number" value="' . $button_id . '" />';
    $output .= "<input type='hidden' value='{$item_name}' name='item_name' />";
    $output .= "<input type='hidden' value='{$payment_amount}' name='item_price' />";
    $output .= "<input type='hidden' value='{$payment_currency}' name='currency_code' />";
    $output .= "<input type='hidden' value='{$custom_field_value}' name='custom' />";

    //Filter to add additional payment input fields to the form.
    $output .= apply_filters('swpm_braintree_payment_form_additional_fields', '');

    $output .= "</form>";
    $output .= '</div>'; //End .swpm_button_wrapper

    return $output;
}
