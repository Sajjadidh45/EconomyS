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

use InvalidArgumentException;
use onebone\economyland\EconomyLand;
use pocketmine\math\Vector2;
use pocketmine\player\Player;
use pocketmine\world\World;

final class Land {
	/** @var EconomyLand */
	private $plugin;
	/** @var string */
	private $id;
	/** @var Vector2 */
	private $start, $end;
	/** @var string */
	private $worldName;
	/** @var World */
	private $world = null;
	/** @var string */
	private $owner;
	/** @var LandOption */
	private $option;
	/** @var LandMeta */
	private $meta;
	/** @var float */
	private $lastAccess = 0;
	/** @var Invitee[] */
	private $invitees = [];

	/**
	 * @param EconomyLand $plugin
	 * @param string $id
	 * @param Vector2 $start
	 * @param Vector2 $end
	 * @param string|World $world
	 * @param string $owner
	 * @param LandOption $option
	 * @param LandMeta $meta
	 */
	public function __construct(EconomyLand $plugin, string $id, Vector2 $start, Vector2 $end, $world,
	                            string $owner, LandOption $option, LandMeta $meta) {
		$this->plugin = $plugin;
		$this->id = $id;

		$this->start = new Vector2(min($start->x, $end->x), min($start->y, $end->y));
		$this->end = new Vector2(max($start->x, $end->x), max($start->y, $end->y));

		if($world instanceof World) {
			$this->worldName = $world->getFolderName();
			$this->world = $world;
		}elseif(is_string($world)) {
			$this->worldName = $world;
			$this->world = $plugin->getServer()->getWorldManager()->getWorldByName($world);
		}else{
			throw new InvalidArgumentException('Invalid $world variable type given to Land constructor');
		}

		$this->owner = strtolower($owner);
		$this->option = $option;
		$this->meta = $meta;
	}

	public function getId(): string {
		return $this->id;
	}

	/**
	 * @return Vector2 Note that Y element of returned Vector2 object actually maps into Z in Minecraft world
	 */
	public function getStart(): Vector2 {
		return $this->start;
	}

	/**
	 * @return Vector2 Note that Y element of returned Vector2 object actually maps into Z in Minecraft world
	 */
	public function getEnd(): Vector2 {
		return $this->end;
	}

	public function getWorldName(): string {
		return $this->worldName;
	}

	/**
	 * Returns the world instance where land reside on.
	 * This method may return null when world is deleted after
	 * land is created.
	 * @return World|null
	 */
	public function getWorld(): ?World {
		if($this->world === null) {
			$this->world = $this->plugin->getServer()->getWorldManager()->getWorldByName($this->worldName);
		}

		return $this->world;
	}

	public function getOwner(): string {
		$this->lastAccess = microtime(true);
		return $this->owner;
	}

	/**
	 * @param Player|string $player
	 * @return bool
	 */
	public function isOwner($player): bool {
		$this->lastAccess = microtime(true);

		if($player instanceof Player) {
			$player = $player->getName();
		}
		$player = strtolower($player);

		return $this->owner === $player;
	}

	public function setOwner(string $owner): void {
		$this->lastAccess = microtime(true);
		$this->owner = strtolower($owner);
	}

	public function getOption(): LandOption {
		$this->lastAccess = microtime(true);
		return clone $this->option;
	}

	public function setOption(LandOption $option): void {
		$this->lastAccess = microtime(true);
		$this->option = $option;
	}

	public function getMeta(): LandMeta {
		$this->lastAccess = microtime(true);
		return clone $this->meta;
	}

	public function setMeta(LandMeta $meta): void {
		$this->lastAccess = microtime(true);
		$this->meta = $meta;
	}

	/**
	 * @param Player|string $player
	 * @return bool
	 */
	public function canInteract($player): bool {
		if($player instanceof Player) {
			$player = $player->getName();
		}
		$player = strtolower($player);

		return isset($this->invitees[$player]);
	}

	/**
	 * @param Player|string $player
	 * @param int $permissions
	 */
	public function addInvitee($player, int $permissions = Invitee::PERMISSION_ALL): void {
		if($player instanceof Player) {
			$player = $player->getName();
		}
		$player = strtolower($player);

		$this->invitees[$player] = new Invitee($player, $permissions);
	}

	/**
	 * @param Player|string $player
	 */
	public function removeInvitee($player): void {
		if($player instanceof Player) {
			$player = $player->getName();
		}
		$player = strtolower($player);

		unset($this->invitees[$player]);
	}

	/**
	 * @return Invitee[]
	 */
	public function getInvitees(): array {
		return $this->invitees;
	}

	public function getLastAccess(): float {
		return $this->lastAccess;
	}

	/**
	 * @param int $x
	 * @param int $z
	 * @return bool
	 */
	public function contains(int $x, int $z): bool {
		return $x >= $this->start->x && $x <= $this->end->x && $z >= $this->start->y && $z <= $this->end->y;
	}

	public function getArea(): int {
		return ($this->end->x - $this->start->x + 1) * ($this->end->y - $this->start->y + 1);
	}
}