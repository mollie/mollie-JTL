<?php


namespace ws_mollie;

use Exception;
use Kunde;
use Lieferschein;
use Lieferscheinpos;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\IncompatiblePlatform;
use Mollie\Api\Resources\BaseResource;
use Mollie\Api\Resources\OrderLine;
use Mollie\Api\Types\OrderStatus;
use RuntimeException;
use Shop;
use Versand;
use ws_mollie\Checkout\AbstractResource;
use ws_mollie\Checkout\OrderCheckout;
use ws_mollie\Model\Shipment as ShipmentModel;

/**
 * Class Shipment
 * @package ws_mollie
 *
 * @property array|null $lines
 * @property string|null $tracking
 *
 */
class Shipment extends AbstractResource
{

    /**
     * @var int|null
     */
    protected $kLieferschein;

    /**
     * @var OrderCheckout
     */
    protected $checkout;
    /**
     * @var \Mollie\Api\Resources\Shipment
     */
    protected $shipment;
    /**
     * @var Lieferschein
     */
    protected $oLieferschein;

    /**
     * @var ShipmentModel
     */
    protected $model;

    /**
     * @var bool
     */
    protected $isGuest = false;

    /**
     * Shipment constructor.
     * @param $kLieferschein
     * @param OrderCheckout|null $checkout
     */
    public function __construct($kLieferschein, OrderCheckout $checkout = null)
    {
        $this->kLieferschein = $kLieferschein;
        if ($checkout) {
            $this->checkout = $checkout;
        }

        if (!$this->getLieferschein() || !$this->getLieferschein()->getLieferschein()) {
            throw new RuntimeException('Lieferschein konnte nicht geladen werden');
        }

        if (!count($this->getLieferschein()->oVersand_arr)) {
            throw new RuntimeException('Kein Versand gefunden!');
        }

        if (!$this->getCheckout()->getBestellung()->oKunde->nRegistriert) {
            $this->isGuest = true;
        }
    }

    public function getLieferschein()
    {
        if (!$this->oLieferschein && $this->kLieferschein) {
            $this->oLieferschein = new Lieferschein($this->kLieferschein);
        }
        return $this->oLieferschein;
    }

    /**
     * @return OrderCheckout
     * @throws Exception
     */
    public function getCheckout()
    {
        if (!$this->checkout) {
            //TODO evtl. load by lieferschien
            throw new Exception('Should not happen, but it did!');
        }
        return $this->checkout;
    }

    public static function syncBestellung(OrderCheckout $checkout)
    {
        $shipments = [];
        if ($checkout->getBestellung()->kBestellung) {

            $oKunde = $checkout->getBestellung()->oKunde ?: new Kunde($checkout->getBestellung()->kKunde);

            /** @var Lieferschein $oLieferschein */
            foreach ($checkout->getBestellung()->oLieferschein_arr as $oLieferschein) {

                try {

                    $shipment = new Shipment($oLieferschein->getLieferschein(), $checkout);

                    $mode = self::Plugin()->oPluginEinstellungAssoc_arr['shippingMode'];
                    switch ($mode) {
                        case 'A':
                            // ship directly
                            if (!$shipment->send() && !$shipment->getShipment()) {
                                throw new RuntimeException('Shipment konnte nicht gespeichert werden.');
                            }
                            $shipments[] = $shipment->getShipment();
                            break;

                        case 'B':
                            // only ship if complete shipping
                            if ($oKunde->nRegistriert || (int)$checkout->getBestellung()->cStatus === BESTELLUNG_STATUS_VERSANDT) {
                                if (!$shipment->send() && !$shipment->getShipment()) {
                                    throw new RuntimeException('Shipment konnte nicht gespeichert werden.');
                                }
                                $shipments[] = $shipment->getShipment();
                                break;
                            }
                            throw new RuntimeException('Gastbestellung noch nicht komplett versendet!');
                    }
                } catch (RuntimeException $e) {
                    $shipments[] = $e->getMessage();
                } catch (Exception $e) {
                    $shipments[] = $e->getMessage();
                    $checkout->Log("mollie: Shipment::syncBestellung (BestellNr. {$checkout->getBestellung()->cBestellNr}, Lieferschein: {$shipment->getLieferschein()->getLieferscheinNr()}) - " . $e->getMessage(), LOGLEVEL_ERROR);
                }
            }
        }
        return $shipments;
    }

    /**
     * @return mixed
     * @throws ApiException
     * @throws IncompatiblePlatform
     * @throws Exception
     */
    public function send()
    {

        if ($this->getShipment()) {
            throw new RuntimeException('Lieferschien bereits an Mollie übertragen: ' . $this->getShipment()->id);
        }

        if ($this->getCheckout()->getMollie(true)->status === OrderStatus::STATUS_COMPLETED) {
            throw new RuntimeException('Bestellung bei Mollie bereits abgeschlossen!');
        }

        $api = $this->getCheckout()->API()->Client();
        $this->shipment = $api->shipments->createForId($this->checkout->getModel()->kID, $this->loadRequest()->getRequestData());

        return $this->updateModel()->saveModel();

    }

    /**
     * @param false $force
     * @return BaseResource|\Mollie\Api\Resources\Shipment
     * @throws ApiException
     * @throws IncompatiblePlatform
     * @throws Exception
     */
    public function getShipment($force = false)
    {
        if (($force || !$this->shipment) && $this->getModel() && $this->getModel()->cShipmentId) {
            $this->shipment = $this->getCheckout()->API()->Client()->shipments->getForId($this->getModel()->cOrderId, $this->getModel()->cShipmentId);
        }
        return $this->shipment;
    }

    /**
     * @return ShipmentModel
     * @throws Exception
     */
    public function getModel()
    {
        if (!$this->model && $this->kLieferschein) {
            $this->model = ShipmentModel::fromID($this->kLieferschein, 'kLieferschein');

            if (!$this->model->dCreated) {
                $this->model->dCreated = date('Y-m-d H:i:s');
            }
            $this->updateModel();
        }
        return $this->model;
    }

    /**
     * @return $this
     * @throws Exception
     */
    public function updateModel()
    {
        $this->getModel()->kLieferschein = $this->kLieferschein;
        if ($this->getCheckout()) {
            $this->getModel()->cOrderId = $this->getCheckout()->getModel()->kID;
            $this->getModel()->kBestellung = $this->getCheckout()->getModel()->kBestellung;
        }
        if ($this->getShipment()) {
            $this->getModel()->cShipmentId = $this->getShipment()->id;
            $this->getModel()->cUrl = $this->getShipment()->getTrackingUrl() ?: '';
        }
        if ($this->getRequestData() && $tracking = $this->RequestData('tracking')) {
            $this->getModel()->cCarrier = $this->RequestData('tracking')['carrier'] ?: '';
            $this->getModel()->cCode = $this->RequestData('tracking')['code'] ?: '';
        }
        return $this;
    }

    /**
     * @param array $options
     * @return $this
     * @throws Exception
     */
    public function loadRequest($options = [])
    {
        /** @var Versand $oVersand */
        $oVersand = $this->getLieferschein()->oVersand_arr[0];
        if ($oVersand->getIdentCode() && $oVersand->getLogistik()) {
            $tracking = [
                'carrier' => $oVersand->getLogistik(),
                'code' => $oVersand->getIdentCode(),
            ];
            if ($oVersand->getLogistikVarUrl()) {
                $tracking['url'] = $oVersand->getLogistikURL();
            }
            $this->tracking = $tracking;
        }

        // TODO: Wenn alle Lieferschiene in der WAWI erstellt wurden, aber nicht im Shop, kommt status 4.
        if ($this->isGuest || (int)$this->getCheckout()->getBestellung()->cStatus === BESTELLUNG_STATUS_VERSANDT) {
            $this->lines = [];
        } else {
            $this->lines = $this->getOrderLines();
        }
        return $this;
    }

    /**
     * @return array
     * @throws Exception
     */
    protected function getOrderLines()
    {
        $lines = [];

        if (!count($this->getLieferschein()->oLieferscheinPos_arr)) {
            return $lines;
        }

        // Bei Stücklisten, sonst gibt es mehrere OrderLines für die selbe ID
        $shippedOrderLines = [];

        /** @var Lieferscheinpos $oLieferscheinPos */
        foreach ($this->getLieferschein()->oLieferscheinPos_arr as $oLieferscheinPos) {

            $wkpos = Shop::DB()->executeQueryPrepared('SELECT * FROM twarenkorbpos WHERE kBestellpos = :kBestellpos', [
                ':kBestellpos' => $oLieferscheinPos->getBestellPos()
            ], 1);

            /** @var OrderLine $orderLine */
            foreach ($this->getCheckout()->getMollie()->lines as $orderLine) {

                if ($orderLine->sku === $wkpos->cArtNr && !in_array($orderLine->id, $shippedOrderLines, true)) {

                    if ($quantity = min($oLieferscheinPos->getAnzahl(), $orderLine->shippableQuantity)) {
                        $lines[] = [
                            'id' => $orderLine->id,
                            'quantity' => $quantity
                        ];
                    }
                    $shippedOrderLines[] = $orderLine->id;
                    break;
                }
            }
        }
        return $lines;
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function saveModel()
    {
        return $this->getModel()->save();
    }
}