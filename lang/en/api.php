<?php

return [

    /*
    |--------------------------------------------------------------------------
    | General
    |--------------------------------------------------------------------------
    */
    'success' => 'Returned successfully',
    'returned_success' => 'Returned successfully',
    'created_success' => 'Created successfully',
    'updated_success' => 'Updated successfully',
    'deleted_success' => 'Deleted successfully',
    'restored_success' => 'Restored successfully',
    'cant_delete_record' => 'Cannot delete this item due to related data',
    'record_not_found' => 'Record not found!',
    'not_found' => 'Not found',
    'fail_data_form' => 'An error occurred in the data',
    'error_in_path_file' => 'Error uploading file, please try again',
    'unauthorized' => 'You do not have the required permissions to perform this action',
    'something_error' => 'An error occurred',
    'profile_updated' => 'Profile updated successfully',
    'test_credentials_success' => 'Credentials tested successfully',
    'not_allowed_to_delete' => 'Not allowed to delete this record',
    'not_allowed_to_restore' => 'Not allowed to restore this record',

    'click_here' => 'Click here',
    'yes' => 'Yes',
    'no' => 'No',

    'home' => 'Dashboard',
    'setting' => 'Setting',
    'user_setting' => 'User Setting',
    'notification' => 'Notification',
    'notifications_updated' => 'Notifications updated',
    'log' => 'Activities',
    'report' => 'Report',

    'create' => 'Create',
    'update' => 'Update',
    'delete' => 'Delete',
    'view-all' => 'View All',
    'view-own' => 'View Own',
    'details' => 'Details',
    'read' => 'Read',
    'export' => 'Export',
    'crud' => 'Data Entry',

    'checkin' => 'Check In',
    'checkout' => 'Check Out',
    'date' => 'Date',
    'created_at' => 'Creation Date',
    'last_login' => 'Last Login',

    'mail_fail' => 'Error in mail settings',
    'mail_success' => 'Email sent successfully',
    'test_email_credentials' => 'Test Email Settings',
    'email_sent_successfully' => 'Message sent to your email successfully',
    'resend_success' => 'Resent successfully',
    'not_data_to_resend' => 'No data to resend',

    'empty_img' => 'No image found to delete!',
    'avatar_deleted' => 'Avatar deleted successfully',
    'no_avatar_found' => 'No avatar found',

    'is_active' => 'Active',
    'status_updated_success' => 'Status updated successfully',
    'update-status' => 'Update Status',
    'record_activated' => 'Record activated successfully',
    'record_deactivated' => 'Record deactivated successfully',
    'model_activated' => ':model activated successfully',
    'model_deactivated' => ':model deactivated successfully',
    'model_not_support_toggle' => 'This model :model does not support activation or deactivation',
    'not_allowed_to_toggle_active' => 'Not allowed to change the activation status of this record',

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */
    'login_success' => 'Logged in successfully',
    'invalid_email_and_password' => 'Email or password is incorrect',
    'account_not_found' => 'Account not found!',
    'account_not_active' => 'Cannot log in because the account is not currently active. Please contact the administration.',
    'password_reset_success' => 'Password updated successfully',
    'reset_password_send_success' => 'Password reset request sent to your email',
    'invalid_reset_token' => 'Password reset token is invalid!',
    'token_not_found' => 'The specified session does not exist.',

    'otp_sent' => 'Verification code sent to your email successfully.',
    'otp_verified' => 'Verification code verified successfully. You can now proceed with the required operation.',
    'invalid_otp' => 'The verification code you entered is incorrect. Please try again.',
    'otp_expired' => 'Verification code has expired. Please request a new verification code to continue.',
    'otp_already_sent_wait' => 'Verification code was sent previously. Please wait :seconds seconds before requesting a new code.',
    'otp_locked_try_later' => 'You have exceeded the allowed number of attempts. Please try again after :seconds seconds.',
    'email_not_registered' => 'The email you entered is not registered with us. Please verify the email or register first.',
    'login_locked_try_later' => 'Sign-in attempts are temporarily blocked. Please try again after :seconds seconds.',
    'not_a_platform_admin' => 'This account is not permitted to access the platform admin console.',

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    */
    'organization' => 'Organization',
    'branch' => 'Branch',
    'account_not_linked_to_organization' => 'This account is not linked to any organization.',
    'account_not_linked_to_branch' => 'This account is not linked to any branch.',
    'feature_not_enabled' => 'This feature is not enabled on your organization subscription.',
    'subscription_not_active' => 'The organization subscription is not active — the account is read-only.',
    'branch_quota_reached' => 'You have reached the maximum number of branches allowed.',
    'user_quota_reached' => 'You have reached the maximum number of users allowed.',
    'cannot_assign_higher_role' => 'You cannot grant a role higher than your own.',
    'cannot_demote_last_super_admin' => 'The last active general manager of the organization cannot be disabled or demoted.',
    'cannot_deactivate_last_branch' => 'The last active branch of the organization cannot be disabled.',
    'branch_outside_scope' => 'The selected branch is outside your scope.',

    /*
    |--------------------------------------------------------------------------
    | Accounting
    |--------------------------------------------------------------------------
    */
    'system_account_missing' => 'System account ":key" is missing from the chart of accounts.',
    'journal_reversal_of' => 'Reversal of entry #:entry_no',
    'entry_unbalanced' => 'The entry is unbalanced — total debit must equal total credit.',
    'entry_needs_two_lines' => 'An entry must contain at least two lines.',
    'account_not_owned' => 'One of the accounts does not belong to your organization.',
    'system_account_locked' => 'A system account\'s structure (code/type) cannot be changed; only its name is editable.',
    'period_locked' => 'The accounting period is locked for this date — posting is not allowed.',
    'expense_memo' => 'Expense: :category — :description',
    'expense_reversal_memo' => 'Expense reversal',
    'asset_acquisition_memo' => 'Asset acquisition: :name',
    'asset_depreciation_memo' => 'Asset depreciation: :name — :period',
    'asset_disposal_memo' => 'Asset disposal: :name',
    'asset_already_disposed' => 'This asset has already been disposed.',
    'asset_has_ledger_footprint' => 'An asset with a ledger footprint cannot be deleted — it must be disposed.',

    /*
    |--------------------------------------------------------------------------
    | Catalog & Customers & Wallet
    |--------------------------------------------------------------------------
    */
    'wallet_invalid_amount' => 'The amount is invalid.',
    'wallet_insufficient_balance' => 'Insufficient wallet balance.',
    'wallet_topup_memo' => 'Wallet top-up: :name',
    'phone_already_used' => 'This phone number is already used by another customer.',
    'customer_has_orders' => 'A customer with existing orders cannot be deleted.',
    'service_type_required' => 'At least one service type is required.',
    'category' => 'Category',
    'product' => 'Product',
    'service' => 'Service',
    'customer' => 'Customer',

    /*
    |--------------------------------------------------------------------------
    | Orders & POS & OTP
    |--------------------------------------------------------------------------
    */
    'customer_has_no_phone' => 'This customer has no phone number.',
    'otp_service_unavailable' => 'The verification code service is not configured.',
    'otp_max_attempts' => 'The allowed number of attempts has been exceeded.',
    'otp_consent_required' => 'Customer OTP verification is required before a wallet debit.',
    'payment_not_confirmed' => 'The payment has not been confirmed.',
    'payment_terminal_reference_required' => 'No network transaction reference received — the payment is rejected.',
    'amount_received_invalid' => 'The amount received is invalid.',
    'order_service_not_found' => 'One of the requested services was not found.',
    'order_items_required' => 'At least one order line is required.',
    'order_number_generation_failed' => 'Could not generate the order number — please retry.',
    'order_cancelled' => 'The order is cancelled.',
    'order_fully_paid' => 'The order is fully paid.',
    'invalid_status_transition' => 'This status transition is not allowed.',
    'order_sale_memo' => 'Sale for cart :order_no',
    'order_reversal_memo' => 'Credit note — cancellation of cart :order_no',
    'no_active_subscription' => 'The customer has no active subscription.',
    'subscription_not_paid' => 'The subscription is not paid — it must be collected first.',
    'subscription_quota_insufficient' => 'Insufficient subscription piece quota (remaining: :remaining).',
    'subscription_balance_insufficient' => 'Insufficient subscription balance (remaining: :remaining).',
    'subscription_plan_not_found' => 'The subscription plan was not found.',
    'subscription_price_zero' => 'The plan price is zero — there is nothing to collect.',
    'subscription_already_paid' => 'The subscription has already been collected.',
    'subscription_collected' => 'The subscription was collected successfully.',
    'subscription_payment_memo' => 'Subscription payment: :name',
    'subscription_consume_memo' => 'Subscription consumption for cart :order_no',

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    */
    'user' => 'User',
    'users_count' => 'Number of Users',
    'user_not_found' => 'User not found.',
    'user_login_success' => 'User logged in successfully',
    'user_created_success' => 'User created successfully',
    'user_updated_success' => 'User updated successfully',
    'user_deleted_success' => 'User deleted successfully',
    'users_deleted_success' => 'Users deleted successfully',
    'cant_user_deleted' => 'You do not have permission to delete the user!',

    'user_logged_out' => 'Logged out successfully from this device.',
    'user_logged_out_all_devices' => 'Logged out from all devices successfully.',
    'user_logged_out_specific' => 'Logged out from the specified session successfully.',

    'root' => 'Root',
    'admin' => 'Admin',
    'role' => 'Role',
    'roles' => 'Roles',
    'permission' => 'Permission',
    'permissions' => 'Permissions',
    'default_role' => 'Default Permission',
    'root_users_count' => 'Number of Default Admins',
    'admin_users_count' => 'Number of Admins',

    'admin_activated' => 'Admin activated successfully.',
    'admin_deactivated' => 'Admin deactivated successfully.',

    'registered_users_by_date' => 'Registered Users by Date',
    'users_by_gender' => 'Users by Gender',

    'name' => 'Name',
    'gender' => 'Gender',
    'male' => 'Male',
    'female' => 'Female',
    'id' => 'ID',
    'note' => 'Note',
    'email' => 'Email',
    'phone' => 'Phone',
    'type' => 'Type',

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    */
    'settings_trans' => [
        'general' => 'General',
        'properties' => 'Properties',
        'notifications' => 'Notifications',
        'theme' => 'Themes',
        'mail_templates' => 'Mail Templates',
        'config' => 'Configuration',
        'info' => 'General Information',
        'contact' => 'Contact Information',
        'social' => 'Social Media',
        'logos' => 'Logos',
        'title' => 'Notifications',
        'colors' => 'Colors',
        'font' => 'Font',
        'otp' => 'OTP Code',
        'generate' => 'Properties',
        'mail' => 'Mail',
        'sms' => 'SMS',
        'ldap' => 'LDAP',
        'reverb' => 'Instant Notifications',
    ],
];
