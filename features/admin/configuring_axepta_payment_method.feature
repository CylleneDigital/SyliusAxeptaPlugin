@managing_axepta_payment_method
Feature: Configuring an Axepta payment method
    In order to accept card payments through BNP Paribas
    As an Administrator
    I want to configure an Axepta payment method

    Background:
        Given the store operates on a single channel in "United States"
        And I am logged in as an administrator

    @ui
    Scenario: Adding an Axepta payment method
        When I want to create a new payment method with "Axepta - BNP Paribas" gateway factory
        And I name it "Axepta" in "English (United States)"
        And I specify its code as "axepta"
        And I configure it with merchant id "BNP_TEST_MERCHANT", hmac key "s3cr3t-hmac-key" and blowfish key "aB3dEf9hJk2mNp5q"
        And I add it
        Then I should be notified that it has been successfully created
        And the payment method "Axepta" should appear in the registry

    @ui
    Scenario: Trying to add an Axepta payment method without its keys
        When I want to create a new payment method with "Axepta - BNP Paribas" gateway factory
        And I name it "Axepta" in "English (United States)"
        And I specify its code as "axepta"
        And I try to add it
        Then I should be notified that the merchant id is required

    # The costliest regression risk: without `always_empty => false` on the key fields, a plain
    # save would replace them with empty strings, and payments would then fail with no visible
    # error - the signature being computed with an empty key.
    @ui
    Scenario: Editing an Axepta payment method without losing its keys
        Given the store has a payment method "Axepta" with a code "axepta" and Axepta gateway
        When I want to modify the "Axepta" payment method
        And I save my changes
        Then I should be notified that it has been successfully edited
        And this payment method should still have its Axepta keys

    @ui
    Scenario: Enabling test mode for the BNP demo account
        Given the store has a payment method "Axepta" with a code "axepta" and Axepta gateway
        When I want to modify the "Axepta" payment method
        And I enable its test mode
        And I save my changes
        Then I should be notified that it has been successfully edited
        And this payment method should be in test mode
