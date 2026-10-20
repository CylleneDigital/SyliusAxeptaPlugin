<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Gateway configuration, entered in the back office.
 *
 * The configuration keys - `merchant_id`, `hmac_key`, `blowfish_key` - are **invariants**: they are
 * stored as such in `sylius_gateway_config.config`. Renaming them would require a data migration.
 */
final class AxeptaGatewayConfigurationType extends AbstractType
{
    /** Codes accepted by the `Language` parameter of the payment page. */
    private const LANGUAGES = ['de', 'en', 'es', 'fr', 'it', 'nl', 'pt'];

    /** @var list<string> fields never redisplayed, hence to preserve when they come back empty. */
    private const SECRET_FIELDS = ['hmac_key', 'blowfish_key'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('merchant_id', TextType::class, [
                'label' => 'cyllene_digital_sylius_axepta.ui.merchant_id',
                'help' => 'cyllene_digital_sylius_axepta.ui.merchant_id_help',
                'constraints' => [
                    new NotBlank(message: 'cyllene_digital_sylius_axepta.merchant_id.not_blank', groups: ['sylius']),
                ],
            ])
            // `always_empty` keeps its default, `true`: the listener at the bottom of this class puts
            // the stored keys back into the submitted data, so with `false` a submission failing
            // validation on any other field would render them in clear in the `value` attribute.
            ->add('hmac_key', PasswordType::class, [
                'label' => 'cyllene_digital_sylius_axepta.ui.hmac_key',
                'help' => 'cyllene_digital_sylius_axepta.ui.hmac_key_help',
                'constraints' => [
                    new NotBlank(message: 'cyllene_digital_sylius_axepta.hmac_key.not_blank', groups: ['sylius']),
                ],
            ])
            ->add('blowfish_key', PasswordType::class, [
                'label' => 'cyllene_digital_sylius_axepta.ui.blowfish_key',
                'help' => 'cyllene_digital_sylius_axepta.ui.blowfish_key_help',
                'constraints' => [
                    new NotBlank(message: 'cyllene_digital_sylius_axepta.blowfish_key.not_blank', groups: ['sylius']),
                ],
            ])
            // Switches no URL: there is only one endpoint, and the merchant identifier is what
            // determines the environment. What this field actually does is force
            // `OrderDesc=Test:0000`, without which the BNP demonstration account refuses test cards
            // without saying why.
            ->add('test_mode', CheckboxType::class, [
                'label' => 'cyllene_digital_sylius_axepta.ui.test_mode',
                'help' => 'cyllene_digital_sylius_axepta.ui.test_mode_help',
                'required' => false,
            ])
            ->add('language', ChoiceType::class, [
                'label' => 'cyllene_digital_sylius_axepta.ui.language',
                'help' => 'cyllene_digital_sylius_axepta.ui.language_help',
                'required' => false,
                'placeholder' => 'cyllene_digital_sylius_axepta.ui.language_follow_order',
                'choices' => array_combine(
                    array_map(static fn (string $language): string => 'cyllene_digital_sylius_axepta.ui.language_' . $language, self::LANGUAGES),
                    self::LANGUAGES,
                ),
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, $this->keepSecretsWhenLeftBlank(...))
        ;
    }

    /**
     * Keeps the existing keys when they come back empty.
     *
     * `PasswordType` never redisplays its value. When editing an existing payment method, both key
     * fields therefore arrive blank on screen, and without this listener their `NotBlank` would
     * refuse every save until both keys are retyped.
     *
     * Accepted corollary: a key cannot be cleared from the form, only replaced. That is the right
     * trade-off for a value whose absence breaks payments.
     */
    private function keepSecretsWhenLeftBlank(FormEvent $event): void
    {
        $submitted = $event->getData();
        $existing = $event->getForm()->getData();

        if (!\is_array($submitted) || !\is_array($existing)) {
            return;
        }

        foreach (self::SECRET_FIELDS as $field) {
            $isBlank = '' === $this->asString($submitted[$field] ?? null);
            $previous = $this->asString($existing[$field] ?? null);

            if ($isBlank && '' !== $previous) {
                $submitted[$field] = $previous;
            }
        }

        $event->setData($submitted);
    }

    /** A submitted value is `mixed`: nothing guarantees a form receives a string. */
    private function asString(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
