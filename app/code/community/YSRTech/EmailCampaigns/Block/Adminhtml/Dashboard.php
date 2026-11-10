<?php
/**
 * The dashboard: a verdict, six figures with their trend, a chart of the
 * period, the funnel, and how each mailbox provider is treating the mail.
 *
 * Charts are drawn here as inline SVG. No chart library, nothing fetched
 * from anywhere else, and they print.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Dashboard extends Mage_Adminhtml_Block_Template
{
    /** Series colours for the period chart, in a fixed order */
    public const COLORS = [
        'delivered' => '#2a78d6',
        'opened'    => '#1baf7a',
        'clicked'   => '#8a4fd3',
        'bounced'   => '#e8730c',
    ];

    /** @var array|null */
    protected $_stats;

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_emailcampaigns/dashboard.phtml');
    }

    public function getDays(): int
    {
        $days = (int) $this->getRequest()->getParam('days', 14);

        return in_array($days, [7, 14, 30], true) ? $days : 14;
    }

    public function getType(): string
    {
        $type = (string) $this->getRequest()->getParam('type', '');

        return in_array($type, ['campaign', 'automation'], true) ? $type : '';
    }

    public function getStats(): array
    {
        if ($this->_stats === null) {
            $this->_stats = Mage::getModel('ysrtech_emailcampaigns/dashboard_stats')
                ->compute($this->getDays(), $this->getType());
        }

        return $this->_stats;
    }

    public function getPeriodUrl(int $days, string $type): string
    {
        return $this->getUrl('*/*/index', array_filter(['days' => $days, 'type' => $type]));
    }

    public function getSeriesLabels(): array
    {
        return [
            'delivered' => $this->__('Delivered'),
            'opened'    => $this->__('Real opens'),
            'clicked'   => $this->__('Clicked'),
            'bounced'   => $this->__('Bounced'),
        ];
    }

    /**
     * The six tiles.
     *
     * @return array<int, array{label:string, value:string, delta:string, good:bool, note:string, tip:string, spark:float[]}>
     */
    public function getTiles(): array
    {
        $s      = $this->getStats();
        $t      = $s['totals'];
        $p      = $s['previous'];
        $series = $s['series'];
        $stats  = YSRTech_EmailCampaigns_Model_Dashboard_Stats::class;
        $rate   = [$stats, 'rate'];
        $pct    = [$stats, 'percent'];

        $define = [
            ['sent', $this->__('Sent'), null, 0, true,
                $this->__('Messages the provider accepted in this period.'),
                $this->__('vs previous %d days', $s['days'])],
            ['delivered', $this->__('Delivered'), 'sent', 1, true,
                $this->__('Sent, less permanent failures. Delivered mail can still be filed as spam.'),
                number_format($t['delivered'])],
            ['opened', $this->__('Real opens'), 'delivered', 1, true,
                $this->__('Messages opened by a person, over delivered. Opens made by Apple Mail Privacy Protection and other prefetching are left out.'),
                $this->__('%s machine opens excluded', number_format($t['machine']))],
            ['clicked', $this->__('Click rate'), 'delivered', 1, true,
                $this->__('Messages with at least one click, over delivered.'),
                $this->__('%s clicked', number_format($t['clicked']))],
            ['bounced', $this->__('Bounce rate'), 'sent', 2, false,
                $this->__('Permanent failures over sent. Keep under 2%.'),
                $this->__('%s bounces', number_format($t['bounced']))],
            ['complained', $this->__('Spam rate'), 'delivered', 3, false,
                $this->__('Spam complaints over delivered. Google and Yahoo require bulk senders to stay under 0.3%; aim for under 0.1%.'),
                $this->__('%s complaints', number_format($t['complained']))],
        ];

        $tiles = [];

        foreach ($define as [$key, $label, $over, $decimals, $upIsGood, $tip, $note]) {
            if ($over === null) {
                $value = number_format($t[$key]);
                $spark = array_map(static fn ($d) => (float) $d[$key], $series);

                if (!$p[$key]) {
                    [$delta, $good] = [$t[$key] ? $this->__('new') : '', true];
                } else {
                    $change = ($t[$key] - $p[$key]) / $p[$key] * 100;
                    $delta  = ($change > 0 ? '+' : ($change < 0 ? "\u{2212}" : '')) . number_format(abs($change), 1) . '%';
                    $good   = $change >= 0;
                }
            } else {
                $now   = $rate($t[$key], $t[$over]);
                $then  = $rate($p[$key], $p[$over]);
                $value = $t[$over] ? $pct($now, $decimals) : "\u{2014}";
                $spark = array_map(static fn ($d) => $rate($d[$key], $d[$over]), $series);

                if (!$p[$over] || !$t[$over]) {
                    [$delta, $good] = ['', true];
                } else {
                    $points = ($now - $then) * 100;
                    $delta  = ($points > 0 ? '+' : ($points < 0 ? "\u{2212}" : "\u{00B1}"))
                        . number_format(abs($points), 2) . ' ' . $this->__('pts');
                    $good   = $points == 0 || ($points > 0) === $upIsGood;
                }
            }

            $tiles[] = [
                'label' => $label, 'value' => $value, 'delta' => $delta, 'good' => $good,
                'note' => $note, 'tip' => $tip, 'spark' => $spark,
            ];
        }

        return $tiles;
    }

    /**
     * A trend line with no axes, for a tile.
     *
     * @param float[] $values
     */
    public function sparkline(array $values, int $width = 120, int $height = 32): string
    {
        if (count($values) < 2) {
            return '';
        }

        $max  = max($values);
        $min  = min($values);
        $span = ($max - $min) ?: 1;
        $step = ($width - 4) / (count($values) - 1);
        $pts  = [];

        foreach (array_values($values) as $i => $value) {
            $pts[] = round(2 + $i * $step, 1) . ',' . round($height - 3 - (($value - $min) / $span) * ($height - 6), 1);
        }

        $last = explode(',', end($pts));

        return '<svg class="ysrtech-ec-spark" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height
            . '" aria-hidden="true"><polyline fill="none" stroke="#2a78d6" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="'
            . implode(' ', $pts) . '"/><circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.5" fill="#2a78d6"/></svg>';
    }

    /**
     * The period chart: one line per outcome, a day per step, one shared axis.
     *
     * Returns the SVG and, beside it, the same figures as JSON for the hover
     * readout the template adds.
     *
     * @return array{svg:string, data:array}
     */
    public function getChart(int $width = 720, int $height = 260): array
    {
        $series = $this->getStats()['series'];
        $keys   = array_keys(self::COLORS);
        $left   = 52;
        $right  = 12;
        $top    = 12;
        $bottom = 28;
        $plotW  = $width - $left - $right;
        $plotH  = $height - $top - $bottom;
        $count  = count($series);

        $max = 0;

        foreach ($series as $day) {
            foreach ($keys as $key) {
                $max = max($max, $day[$key]);
            }
        }

        $max  = $this->_niceCeil($max ?: 1);
        $x    = static fn (int $i) => round($left + ($count > 1 ? $i * $plotW / ($count - 1) : $plotW / 2), 1);
        $y    = static fn ($v) => round($top + $plotH - ($v / $max) * $plotH, 1);
        $svg  = '<svg class="ysrtech-ec-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="'
            . $this->escapeHtml($this->__('Daily delivered, real opens, clicks and bounces')) . '">';

        // Recessive grid: four steps and the baseline
        for ($g = 0; $g <= 4; $g++) {
            $value = $max * $g / 4;
            $gy    = $y($value);
            $svg  .= '<line x1="' . $left . '" x2="' . ($width - $right) . '" y1="' . $gy . '" y2="' . $gy
                . '" stroke="' . ($g === 0 ? '#b5b5b5' : '#e6e6e6') . '" stroke-width="1"/>'
                . '<text x="' . ($left - 8) . '" y="' . ($gy + 4) . '" text-anchor="end" class="axis">'
                . $this->_short($value) . '</text>';
        }

        // Every second or fifth day, counted back from today so that the
        // most recent day always carries its date.
        $every = $count > 14 ? 5 : ($count > 7 ? 2 : 1);

        foreach ($series as $i => $day) {
            if (($count - 1 - $i) % $every === 0) {
                $svg .= '<text x="' . $x($i) . '" y="' . ($height - 8) . '" text-anchor="'
                    . ($i === $count - 1 ? 'end' : 'middle') . '" class="axis">'
                    . $this->escapeHtml(date('M j', strtotime($day['date']))) . '</text>';
            }
        }

        foreach ($keys as $key) {
            $pts = [];

            foreach ($series as $i => $day) {
                $pts[] = $x($i) . ',' . $y($day[$key]);
            }

            $svg .= '<polyline fill="none" stroke="' . self::COLORS[$key]
                . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $pts) . '"/>';
        }

        $svg .= '<line class="cross" x1="0" x2="0" y1="' . $top . '" y2="' . ($top + $plotH)
            . '" stroke="#8a8a8a" stroke-width="1" style="display:none"/></svg>';

        return [
            'svg'  => $svg,
            'data' => [
                'left' => $left, 'plotW' => $plotW, 'width' => $width,
                'days' => array_map(static function ($day) use ($keys) {
                    return ['label' => date('D, M j', strtotime($day['date']))] + array_intersect_key($day, array_flip($keys));
                }, $series),
            ],
        ];
    }

    private function _niceCeil(float $value): float
    {
        $magnitude = pow(10, floor(log10($value)));

        foreach ([1, 2, 2.5, 4, 5, 8, 10] as $step) {
            if ($value <= $step * $magnitude) {
                return $step * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    private function _short(float $value): string
    {
        if ($value >= 1000000) {
            return rtrim(rtrim(number_format($value / 1000000, 1), '0'), '.') . 'M';
        }

        if ($value >= 1000) {
            return rtrim(rtrim(number_format($value / 1000, 1), '0'), '.') . 'k';
        }

        return (string) round($value);
    }
}
