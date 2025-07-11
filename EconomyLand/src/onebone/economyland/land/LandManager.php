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

namespace onebone\economyland\land;

use onebone\economyland\EconomyLand;
use onebone\economyland\provider\Provider;
use onebone\economyland\task\LandUnloadTask;
use pocketmine\world\World;
use pocketmine\math\Vector2;
use pocketmine\player\Player;

class LandManager {
	/** @var EconomyLand */
	private $plugin;
	/** @var Provider */
	private $provider;
	/** @var Land[] */
	private $lands = [];

	public function __construct(EconomyLand $plugin, Provider $provider) {
		$this->plugin = $plugin;
		$this->provider = $provider;
	}

	public function getLand(string $id): ?Land {
		if(isset($this->lands[$id])) {
			return $this->lands[$id];
		}

		$land = $this->provider->getLand($id);
		if($land !== null) {
			$this->lands[$id] = $land;
		}

		return $land;
	}

	public function getLandByPosition(int $x, int $z, string $worldName): ?Land {
		foreach($this->lands as $land) {
			if($land->getWorldName() === $worldName and $land->isInside(new Vector2($x, $z))) {
				return $land;
			}
		}

		return $this->provider->getLandByPosition($x, $z, $worldName);
	}

	public function getLandsByOwner(string $owner): array {
		return $this->provider->getLandsByOwner($owner);
	}

	public function addLand(Land $land): void {
		$this->lands[$land->getId()] = $land;
		$this->provider->addLand($land);
	}

	public function removeLand(string $id): bool {
		if(isset($this->lands[$id])) {
			unset($this->lands[$id]);
		}

		// Provider should handle removal
		return true;
	}

	public function getMatches(string $id): array {
		return $this->provider->getMatches($id);
	}

	public function checkCollision(Vector2 $start, Vector2 $end, string $worldName, ?string $excludeId = null): ?Land {
		foreach($this->lands as $land) {
			if($land->getWorldName() === $worldName and 
			   ($excludeId === null or $land->getId() !== $excludeId) and 
			   $land->checkCollision($start, $end)) {
				return $land;
			}
		}

		return null;
	}

	public function unloadLand(string $id): void {
		if(isset($this->lands[$id])) {
			$land = $this->lands[$id];
			if(microtime(true) - $land->getLastAccess() > $this->plugin->getPluginConfig()->getLandUnloadAfter()) {
				unset($this->lands[$id]);
			}
		}
	}

	public function save(): void {
		$this->provider->save();
	}

	public function close(): void {
		$this->provider->close();
	}
}
