<?php

namespace justinholtweb\coinpurse\conditions;

use Craft;
use craft\base\conditions\BaseSelectConditionRule;
use craft\base\ElementInterface;
use craft\commerce\elements\db\OrderQuery;
use craft\commerce\elements\Order;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\coinpurse\Plugin;
use justinholtweb\coinpurse\services\ExpressOrders;

/**
 * "Express checkout" on order conditions: Commerce → Orders custom sources, filters, and anything
 * else built on `OrderCondition`.
 *
 * Deliberately not offered on discount, shipping or gateway conditions. Those are evaluated on a
 * cart, while the wallet sheet is still open — and Coin Purse refuses a charge whose total differs
 * from the one the sheet showed. The wallet is only known once the customer has authorised the
 * payment, so a discount that switched on at that moment would fail every order it applied to.
 */
class ExpressCheckoutConditionRule extends BaseSelectConditionRule implements ElementConditionRuleInterface
{
    public string $value = ExpressOrders::ANY;

    public function getLabel(): string
    {
        return Craft::t('coinpurse', 'Express checkout');
    }

    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    protected function options(): array
    {
        $options = [
            ExpressOrders::ANY => Craft::t('coinpurse', 'Any wallet'),
        ];

        foreach (Plugin::getInstance()->getExpressOrders()->walletLabels() as $wallet => $label) {
            $options[$wallet] = $label;
        }

        $options[ExpressOrders::NONE] = Craft::t('coinpurse', 'Not express checkout');

        return $options;
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var OrderQuery $query */
        $orderIds = Plugin::getInstance()->getExpressOrders()->orderIdSubQuery($this->value);

        $query->andWhere(
            $this->value === ExpressOrders::NONE
                ? ['not in', 'elements.id', $orderIds]
                : ['in', 'elements.id', $orderIds]
        );
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Order $element */
        $wallet = $element->id
            ? Plugin::getInstance()->getExpressOrders()->walletFor((int)$element->id)
            : null;

        return match ($this->value) {
            ExpressOrders::ANY => $wallet !== null,
            ExpressOrders::NONE => $wallet === null,
            default => $wallet === $this->value,
        };
    }
}
