<?php

namespace App\Form;

use App\Entity\InvoiceItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** One line on the invoice: Qty | Description | Unit price | Amount. */
class InvoiceItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('quantity', NumberType::class, [
                'label' => 'Qty',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0', 'class' => 'js-item-qty'],
                'row_attr' => ['class' => 'col-6 col-md-2'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'attr' => ['rows' => 1, 'placeholder' => 'e.g. Website maintenance – September'],
                'row_attr' => ['class' => 'col-12 col-md-5'],
            ])
            ->add('unitPrice', NumberType::class, [
                'label' => 'Unit price',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0', 'class' => 'js-item-price'],
                'row_attr' => ['class' => 'col-6 col-md-2'],
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Amount',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'disabled' => true,
                'required' => false,
                'help' => 'Qty × unit price',
                'attr' => ['class' => 'js-item-amount', 'readonly' => 'readonly', 'tabindex' => '-1'],
                'row_attr' => ['class' => 'col-12 col-md-3'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InvoiceItem::class,
            'attr' => ['class' => 'row invoice-item-row'],
        ]);
    }
}
