<?php

namespace App\Service\Geo;

class GeoPtDistrictService
{
    public $districtList = [];

    function setArray() {
        $this->districtList = [
            [
                'id' => 1,
                'name' => 'Lisboa',
            ],
            [
                'id' => 2,
                'name' => 'Porto',
            ],
            [
                'id' => 3,
                'name' => 'Braga',
            ],
            [
                'id' => 4,
                'name' => 'Setúbal',
            ],
            [
                'id' => 5,
                'name' => 'Coimbra',
            ],
            [
                'id' => 6,
                'name' => 'Leiria',
            ],
            [
                'id' => 7,
                'name' => 'Madeira',
            ],
            [
                'id' => 8,
                'name' => 'Viseu',
            ],
            [
                'id' => 9,
                'name' => 'Viana do Castelo',
            ],
            [
                'id' => 10,
                'name' => 'Aveiro',
            ],
            [
                'id' => 11,
                'name' => 'Faro',
            ],
            [
                'id' => 12,
                'name' => 'Azores',
            ],
            [
                'id' => 13,
                'name' => 'Santarém',
            ],
            [
                'id' => 14,
                'name' => 'Castelo Branco',
            ],
            [
                'id' => 15,
                'name' => 'Évora',
            ],
            [
                'id' => 16,
                'name' => 'Vila Real',
            ],
            [
                'id' => 17,
                'name' => 'Guarda',
            ],
            [
                'id' => 18,
                'name' => 'Beja',
            ],
            [
                'id' => 19,
                'name' => 'Bragança',
            ],
            [
                'id' => 20,
                'name' => 'Portalegre',
            ],
      ];
    }

    function listAll() {
       $this->setArray();

       return $this->districtList;
    }

    function getById($id) {
        $this->setArray();

        if ($position = array_search($id, array_column($this->districtList, 'id'))) {
            return $this->districtList[$position];
        }

        return [];
    }
}