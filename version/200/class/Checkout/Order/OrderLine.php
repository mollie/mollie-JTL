<?php


namespace ws_mollie\Checkout\Order;


use Bestellung;
use JsonSerializable;
use Mollie\Api\Types\OrderLineType;
use RuntimeException;
use stdClass;
use WarenkorbPos;
use WarenkorbPosEigenschaft;
use ws_mollie\Checkout\Payment\Amount;
use ws_mollie\Traits\Jsonable;

class OrderLine implements JsonSerializable
{

    use Jsonable;

    public $type;

    public $category;

    public $name;

    public $quantity;

    public $unitPrice;

    public $discountAmount;

    public $totalAmount;

    public $vatRate;

    public $vatAmount;

    public $sku;

    public $imageUrl;

    public $productUrl;

    public $metadata;

    /**
     * OrderLine constructor.
     * @param stdClass|WarenkorbPos $oPosition
     * @param stdClass $currency
     */
    public function __construct($oPosition = null, $currency = null)
    {
        if(!$oPosition || !$currency){
            return;
        }

        $this->type = self::getType($oPosition->nPosTyp);
        $this->name = $oPosition->cName;

        $_vatRate = (float)$oPosition->fMwSt / 100;
        if ((int)$oPosition->nPosTyp === C_WARENKORBPOS_TYP_KUPON) {
            $_netto = round($oPosition->fPreis * (1 + $_vatRate), 4);
            $_vatRate = 0;
        } else {
            $_netto = round($oPosition->fPreis, 4);
        }
        $_amount = (float)$oPosition->nAnzahl;

        if (fmod($oPosition->nAnzahl, 1) !== 0.0) {
            $_netto *= $_amount;
            $_amount = 1;
            $this->name .= sprintf(" (%.2f %s)", (float)$oPosition->nAnzahl, $oPosition->cEinheit);
        }

        // TODO vorher 2
        $unitPriceNetto = round(($currency->fFaktor * $_netto), 4);
        $unitPrice = round($unitPriceNetto * (1 + $_vatRate), 2);
        $totalAmount = round($_amount * $unitPrice, 2);
        $vatAmount = round($totalAmount - ($totalAmount / (1 + $_vatRate)), 2);

        $this->quantity = (int)$_amount;
        $this->unitPrice = new Amount($unitPrice, $currency, false);
        $this->totalAmount = new Amount($totalAmount, $currency, false);
        $this->vatRate = number_format($_vatRate * 100, 2);
        $this->vatAmount = new Amount($vatAmount, $currency, false);

        $metadata = [];

        if (isset($oPosition->Artikel)) {
            $this->sku = $oPosition->Artikel->cArtNr;
            $metadata['kArtikel'] = $oPosition->kArtikel;
            if ($oPosition->cUnique !== '') {
                $metadata['cUnique'] = $oPosition->cUnique;
            }
        }

        if (isset($oPosition->WarenkorbPosEigenschaftArr) && is_array($oPosition->WarenkorbPosEigenschaftArr) && count($oPosition->WarenkorbPosEigenschaftArr)) {
            $metadata['properties'] = [];
            /** @var WarenkorbPosEigenschaft $eigenschaft */
            foreach ($oPosition->WarenkorbPosEigenschaftArr as $eigenschaft) {
                $metadata['properties'][] = [
                    'kEigenschaft' => (int)$eigenschaft->kEigenschaft,
                    'kEigenschaftWert' => (int)$eigenschaft->kEigenschaftWert,
                    'name' => $eigenschaft->cEigenschaftName,
                    'value' => $eigenschaft->cEigenschaftWertName,
                ];
                if (strlen(json_encode($metadata)) > 1000) {
                    array_pop($metadata['properties']);
                    break;
                }
            }

        }
        $this->metadata = $metadata;
    }

    /**
     * @param $nPosTyp
     * @return string
     */
    protected static function getType($nPosTyp)
    {
        switch ($nPosTyp) {
            case C_WARENKORBPOS_TYP_ARTIKEL:
            case C_WARENKORBPOS_TYP_GRATISGESCHENK:
                // TODO: digital / Download Artikel?
                return OrderLineType::TYPE_PHYSICAL;

            case C_WARENKORBPOS_TYP_VERSANDPOS:
                return OrderLineType::TYPE_SHIPPING_FEE;

            case C_WARENKORBPOS_TYP_VERPACKUNG:
            case C_WARENKORBPOS_TYP_VERSANDZUSCHLAG:
            case C_WARENKORBPOS_TYP_ZAHLUNGSART:
            case C_WARENKORBPOS_TYP_VERSAND_ARTIKELABHAENGIG:
            case C_WARENKORBPOS_TYP_NACHNAHMEGEBUEHR:
                return OrderLineType::TYPE_SURCHARGE;

            case C_WARENKORBPOS_TYP_GUTSCHEIN:
            case C_WARENKORBPOS_TYP_KUPON:
            case C_WARENKORBPOS_TYP_NEUKUNDENKUPON:
                return OrderLineType::TYPE_DISCOUNT;

        }

        throw new RuntimeException('Unknown PosTyp.', (int)$nPosTyp);
    }

    /**
     * @param OrderLine[] $orderLines
     * @param Amount $amount
     * @param stdClass $currency
     * @return OrderLine|null
     */
    public static function getRoundingCompensation(array $orderLines, Amount $amount, $currency)
    {
        $sum = .0;
        foreach ($orderLines as $line) {
            $sum += (float)$line->totalAmount->value;
        }
        if (abs($sum - (float)$amount->value()) > 0) {
            $diff = (round((float)$amount->value() - $sum, 2));
            if ($diff !== 0.0) {
                $line = new self();
                $line->type = $diff > 0 ? OrderLineType::TYPE_SURCHARGE : OrderLineType::TYPE_DISCOUNT;
                $line->name = 'Rundungsausgleich';
                $line->quantity = 1;
                $line->unitPrice = new Amount($diff, $currency, false, false);

                $line->totalAmount = new Amount($diff, $currency, false, false);
                $line->vatRate = "0.00";
                $line->vatAmount = new Amount(0, $currency, false, false);
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
        $line = new self();
        $line->type = OrderLineType::TYPE_STORE_CREDIT;
        $line->name = 'Guthaben';
        $line->quantity = 1;
        $line->unitPrice = new Amount($oBestellung->fGuthaben, $oBestellung->Waehrung, true);
        $line->totalAmount = $line->unitPrice;

        $line->vatRate = "0.00";
        $line->vatAmount = new Amount(0, $oBestellung->Waehrung, true);

        return $line;
    }

}