@paying_with_axepta
Feature: Paying with Axepta
    In order to buy products
    As a Customer
    I want to be able to pay with my card through the BNP Paribas payment page

    Background:
        Given the store operates on a single channel in "United States"
        And the store has a product "PHP Mug" priced at "$42.00"
        And the store ships everywhere for free
        And the store has a payment method "Axepta" with a code "axepta" and Axepta gateway on the PaymentRequest path
        And I am a logged in customer
        And I have product "PHP Mug" in the cart
        And I have proceeded selecting "Axepta" payment method

    # The payment page is hosted by the bank: the scenario stops at the signed request, no
    # outbound call happens from the shop.
    @ui
    Scenario: Preparing a signed request for the hosted payment page
        Given I have confirmed order
        When the shop has sent the payment request to the bank
        Then the payment request should target the Axepta payment page
        And it should carry a signed payload

    @ui
    Scenario: Successfully paying with Axepta
        Given I have confirmed order
        And the shop has sent the payment request to the bank
        When the bank notifies the shop that the payment succeeded
        Then my order should be paid

    @ui
    Scenario: Retrying after a refused payment
        Given I have confirmed order
        And the shop has sent the payment request to the bank
        When the bank notifies the shop that the payment was refused
        Then my order should not be paid
        And I should be able to pay for my order again

    # A double notification is the nominal case at BNP, not the exception.
    @ui
    Scenario: Receiving the same notification twice
        Given I have confirmed order
        And the shop has sent the payment request to the bank
        And the bank notifies the shop that the payment succeeded
        When the bank sends the same notification again
        Then my order should be paid
        And the shop should have answered the bank without an error

    @ui
    Scenario: Rejecting a forged notification
        Given I have confirmed order
        And the shop has sent the payment request to the bank
        When someone sends a notification with an invalid signature
        Then my order should not be paid
