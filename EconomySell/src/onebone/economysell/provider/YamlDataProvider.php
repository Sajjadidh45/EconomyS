<?php

/*
 * EconomyS, the massive economy plugin with many features for PocketMine-MP
 * Copyright (C) 2013-2021  onebone <me@onebone.me>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace onebone\economysell\provider;

use pocketmine\world\World;
use pocketmine\world\Position;
use pocketmine\utils\Config;

class YamlDataProvider implements DataProvider {
	/** @var Config */
	private $config;
	/** @var string */
	private $file;
	/** @var bool */
	private $save;

	public function __construct(string $file, bool $save) {
		$this->file = $file;
		$this->save = $save;
		$this->config = new Config($file, Config::YAML);
	}

	public function addSell($x, $y = 0, $z = 0, $level = null, $data = []) {
		if($x instanceof Position) {
			$level = $x->getWorld()->getFolderName();
			$y = $x->getFloorY();
			$z = $x->getFloorZ();
			$x = $x->getFloorX();
		} elseif($level instanceof World) {
			$level = $level->getFolderName();
		}

		$this->config->setNested("$level.$x.$y.$z", $data);
		if($this->save) {
			$this->config->save();
		}
		return true;
	}

	public function getSell($x, $y = 0, $z = 0, $level = null) {
		if($x instanceof Position) {
			$level = $x->getWorld()->getFolderName();
			$y = $x->getFloorY();
			$z = $x->getFloorZ();
			$x = $x->getFloorX();
		} elseif($level instanceof World) {
			$level = $level->getFolderName();
		}

		return $this->config->getNested("$level.$x.$y.$z");
	}

	public function removeSell($x, $y = 0, $z = 0, $level = null) {
		if($x instanceof Position) {
			$level = $x->getWorld()->getFolderName();
			$y = $x->getFloorY();
			$z = $x->getFloorZ();
			$x = $x->getFloorX();
		} elseif($level instanceof World) {
			$level = $level->getFolderName();
		}

		$this->config->removeNested("$level.$x.$y.$z");
		if($this->save) {
			$this->config->save();
		}
		return true;
	}

	public function getAll() {
		return $this->config->getAll();
	}

	public function getProviderName() {
		return "Yaml";
	}

	public function save() {
		$this->config->save();
	}

	public function close() {
		$this->config->save();
	}
}