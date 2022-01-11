<?php

/**
 * @copyright 2022 WebStollen GmbH
 * @link https://www.webstollen.de
 */

namespace ws_mollie\Checkout\Order;

use Bestellung;
use Mollie\Api\Types\OrderLineType;
use RuntimeException;
use stdClass;
use WarenkorbPos;
use WarenkorbPosEigenschaft;
use ws_mollie\Checkout\AbstractResource;
use ws_mollie\Checkout\Exception\ResourceValidityException;
use ws_mollie\Checkout\Payment\Amount;

/**
 * Class OrderLine
 * @package ws_mollie\Checkout\Order
 *
 * @property null|string $type
 * @property null|string $category One of: meal, eco, gift
 * @property string $name
 * @property int $quantity
 * @property Amount $unitPrice
 * @property null|Amount $discountAmount
 * @property Amount $totalAmount
 * @property string $vatRate
 * @property Amount $vatAmount
 * @property null|string $sku
 * @property null|string $imageUrl
 * @property null|string $productUrl
 * @property null|array|stdClass|string $metadata max. 1 kB
 *
 */
class OrderLine extends AbstractResource
{
    /**
     * @param OrderLine[] $orderLines
     * @param Amount      $amount
     * @param string      $currency
     * @return null|OrderLine
     */
    public static function getRoundingCompensation(array $orderLines, Amount $amount, $currency)
    {
        $sum = .0;
        foreach ($orderLines as $line) {
            $sum += (float)$line->totalAmount->value;
        }
        if (abs($sum - (float)$amount->value) > 0) {
            $diff = (round((float)$amount->value - $sum, 2));
            if ($diff !== 0.0) {
                $line       = new self();
                $line->type = $diff > 0 ? OrderLineType::TYPE_SURCHARGE : OrderLineType::TYPE_DISCOUNT;
                // TODO: Translation needed?
                $line->name        = 'Rundungsausgleich';
                $line->quantity    = 1;
                $line->unitPrice   = Amount::factory($diff, $currency);
                $line->totalAmount = Amount::factory($diff, $currency);
                $line->vatRate     = '0.00';
                $line->vatAmount   = Amount::factory(0, $currency);

                return $line;
            }
        }

        return null;
    }

    /**
     * @param Bestellung $oBestellung
     * @return OrderLine
     */
    public static function getCredit(Bestellung $oBestellung)
    {
        $line           = new self();
        $line->type     = OrderLineType::TYPE_STORE_CREDIT;
        $line->name     = 'Guthaben';
        $line->quantity = 1;
        // TODO: check currency of Guthaben
        $line->unitPrice   = Amount::factory($oBestellung->fGuthaben, $oBestellung->Waehrung->cISO);
        $line->totalAmount = $line->unitPrice;
        $line->vatRate     = '0.00';
        $line->vatAmount   = Amount::factory(0, $oBestellung->Waehrung->cISO);

        return $line;
    }

    /**
     * @param stdClass|WarenkorbPos $oPosition
     * @param null                  $currency
     * @return OrderLine
     */
    public static function factory($oPosition, $currency = null)
    {
        if (!$oPosition) {
            throw new RuntimeException('$oPosition invalid:', print_r($oPosition));
        }

        $resource = new static();

        $resource->fill($oPosition, $currency);

        // Validity Check
        if (
            !$resource->name || !$resource->quantity || !$resource->unitPrice || !$resource->totalAmount
            || !$resource->vatRate || !$resource->vatAmount
        ) {
            throw ResourceValidityException::trigger(
                ResourceValidityException::ERROR_REQUIRED,
                ['name', 'quantity', 'unitPrice', 'totalAmount', 'vatRate', 'vatAmount'],
                $resource
            );
        }

        return $resource;
    }

    /**
     * @param stdClass|WarenkorbPos $oPosition
     * @param null|stdClass         $currency
     * @return $this
     * @todo Setting for Fraction handling needed?
     */
    protected function fill($oPosition, $currency = null)
    {
        if (!$currency) {
            $currency = Amount::FallbackCurrency();
        }

        $isKupon = (int)$oPosition->nPosTyp === (int)C_WARENKORBPOS_TYP_KUPON;
        $isFrac  = fmod($oPosition->nAnzahl, 1) !== 0.0;

        // Kupon? set vatRate to 0 and adjust netto
        $vatRate = $isKupon ? 0 : (float)$oPosition->fMwSt / 100;
        $netto   = $isKupon ? round($oPosition->fPreis * (1 + $vatRate), 4) : round($oPosition->fPreis, 4);

        // Fraction? transform, as it were 1, and set quantity to 1
        $netto          = round(($isFrac ? $netto * (float)$oPosition->nAnzahl : $netto) * $currency->fFaktor, 4);
        $this->quantity = $isFrac ? 1 : (int)$oPosition->nAnzahl;

        // Fraction? include quantity and unit in name
        $this->name = $isFrac ? sprintf('%s (%.2f %s)', $oPosition->cName, (float)$oPosition->nAnzahl, $oPosition->cEinheit) : $oPosition->cName;

        $this->mapType($oPosition->nPosTyp);

        //$unitPriceNetto = round(($currency->fFaktor * $netto), 4);

        $this->unitPrice   = Amount::factory(round($netto * (1 + $vatRate), 2), $currency->cISO, false);
        $this->totalAmount = Amount::factory(round($this->quantity * $this->unitPrice->value, 2), $currency->cISO, false);

        $this->vatRate   = number_format($vatRate * 100, 2);
        $this->vatAmount = Amount::factory(round($this->totalAmount->value - ($this->totalAmount->value / (1 + $vatRate)), 2), $currency->cISO, false);

        $metadata = [];

        // Is Artikel ?
        if (isset($oPosition->Artikel)) {
            $this->sku            = $oPosition->Artikel->cArtNr;
            $metadata['kArtikel'] = $oPosition->kArtikel;
            if ($oPosition->cUnique !== '') {
                $metadata['cUnique'] = utf8_encode($oPosition->cUnique);
            }
        }

        if (isset($oPosition->WarenkorbPosEigenschaftArr) && is_array($oPosition->WarenkorbPosEigenschaftArr) && count($oPosition->WarenkorbPosEigenschaftArr)) {
            $metadata['properties'] = [];
            /** @var WarenkorbPosEigenschaft $warenkorbPosEigenschaft */
            foreach ($oPosition->WarenkorbPosEigenschaftArr as $warenkorbPosEigenschaft) {
                $metadata['properties'][] = [
                    'kEigenschaft'     => (int)$warenkorbPosEigenschaft->kEigenschaft,
                    'kEigenschaftWert' => (int)$warenkorbPosEigenschaft->kEigenschaftWert,
                    'name'             => utf8_encode($warenkorbPosEigenschaft->cEigenschaftName),
                    'value'            => utf8_encode($warenkorbPosEigenschaft->cEigenschaftWertName),
                ];
                if (strlen(json_encode($metadata)) > 1000) {
                    array_pop($metadata['properties']);

                    break;
                }
            }
        }
        if (json_encode($metadata) !== false) {
            $this->metadata = $metadata;
        }

        return $this;
    }

    /**
     * @param $nPosTyp
     * @return OrderLine
     */
    protected function mapType($nPosTyp)
    {
        switch ($nPosTyp) {
            case C_WARENKORBPOS_TYP_ARTIKEL:
            case C_WARENKORBPOS_TYP_GRATISGESCHENK:
                // TODO: digital / Download Artikel?
                $this->type = OrderLineType::TYPE_PHYSICAL;

                return $this;

            case C_WARENKORBPOS_TYP_VERSANDPOS:
                $this->type = OrderLineType::TYPE_SHIPPING_FEE;

                return $this;

            case C_WARENKORBPOS_TYP_VERPACKUNG:
            case C_WARENKORBPOS_TYP_VERSANDZUSCHLAG:
            case C_WARENKORBPOS_TYP_ZAHLUNGSART:
            case C_WARENKORBPOS_TYP_VERSAND_ARTIKELABHAENGIG:
            case C_WARENKORBPOS_TYP_NACHNAHMEGEBUEHR:
                $this->type = OrderLineType::TYPE_SURCHARGE;

                return $this;

            case C_WARENKORBPOS_TYP_GUTSCHEIN:
            case C_WARENKORBPOS_TYP_KUPON:
            case C_WARENKORBPOS_TYP_NEUKUNDENKUPON:
                $this->type = OrderLineType::TYPE_DISCOUNT;

                return $this;
        }

        throw new RuntimeException('Unknown PosTyp.', (int)$nPosTyp);
    }
}
