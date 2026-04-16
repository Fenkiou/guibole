<?php

declare(strict_types=1);

namespace Bga\Games\guibole;

class Material
{
    private array $colors;

    public function __construct()
    {
        $this->colors = [
            1 => [
                'name' => clienttranslate('diamond'),
                'nametr' => 'diamond',
            ],
            2 => [
                'name' => clienttranslate('club'),
                'nametr' => 'club',
            ],
            3 => [
                'name' => clienttranslate('heart'),
                'nametr' => 'heart',
            ],
            4 => [
                'name' => clienttranslate('spade'),
                'nametr' => 'spade',
            ],
        ];
    }

    public function getColors(): array
    {
        return $this->colors;
    }
}
