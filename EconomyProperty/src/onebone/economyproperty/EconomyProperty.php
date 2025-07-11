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

namespace onebone\economyproperty;

use onebone\economyapi\EconomyAPI;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\world\Position;
use pocketmine\world\World;
use SQLite3;

class EconomyProperty extends PluginBase implements Listener {
	/**
	 * @var SQLite3
	 */
	private $property;
	/**
	 * @var array $touch
	 * key : player name
	 * value : null
	 */
	private $tap, $placeQueue, $touch;
	/**
	 * @var PropertyCommand $command
	 */
	private $command;
	/**
	 * @var EconomyAPI
	 */
	private $api;

	public function onEnable(): void {
		if(!file_exists($this->getDataFolder())) {
			mkdir($this->getDataFolder());
		}

		$this->api = EconomyAPI::getInstance();
		if($this->api === null) {
			$this->getLogger()->critical("EconomyAPI plugin not found!");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		$this->property = new SQLite3($this->getDataFolder() . "Property.sqlite3");
		$this->property->exec(stream_get_contents($resource = $this->getResource("sqlite3.sql")));
		@fclose($resource);
		$this->parseOldData();

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->saveDefaultConfig();
		$command = $this->getConfig()->get("commands");
		$this->command = new PropertyCommand($this, $command["command"], $command["pos1"], $command["pos2"], $command["make"], $command["touchPos"]);
		$this->getServer()->getCommandMap()->register("economyproperty", $this->command);

		$this->tap = [];
		$this->touch = [];
		$this->placeQueue = [];

		$this->getLogger()->info("EconomyProperty has been enabled");
	}

	public function onDisable(): void {
		if($this->property instanceof SQLite3) {
			$this->property->close();
		}
	}

	public function getAPI(): EconomyAPI {
		return $this->api;
	}

	public function getDatabase(): SQLite3 {
		return $this->property;
	}

	/**
	 * @param PlayerInteractEvent $event
	 * @priority HIGHEST
	 * @ignoreCancelled true
	 */
	public function onPlayerInteract(PlayerInteractEvent $event): void {
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$pos = $block->getPosition();

		if(isset($this->tap[strtolower($player->getName())])) {
			$this->handleTap($player, $pos);
			$event->cancel();
		}
	}

	/**
	 * @param BlockPlaceEvent $event
	 * @priority HIGHEST
	 * @ignoreCancelled true
	 */
	public function onBlockPlace(BlockPlaceEvent $event): void {
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$pos = $block->getPosition();

		if($this->isProtected($pos) && !$this->canUse($player, $pos)) {
			$player->sendMessage("§cThis area is protected!");
			$event->cancel();
		}
	}

	/**
	 * @param BlockBreakEvent $event
	 * @priority HIGHEST
	 * @ignoreCancelled true
	 */
	public function onBlockBreak(BlockBreakEvent $event): void {
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$pos = $block->getPosition();

		if($this->isProtected($pos) && !$this->canUse($player, $pos)) {
			$player->sendMessage("§cThis area is protected!");
			$event->cancel();
		}
	}

	private function handleTap(Player $player, Position $pos): void {
		$playerName = strtolower($player->getName());
		$action = $this->tap[$playerName];

		switch($action) {
			case "pos1":
				$this->setPos1($player, $pos);
				break;
			case "pos2":
				$this->setPos2($player, $pos);
				break;
			case "touchpos":
				$this->touchPos($player, $pos);
				break;
		}

		unset($this->tap[$playerName]);
	}

	public function setPos1(Player $player, Position $pos): void {
		$playerName = strtolower($player->getName());
		if(!isset($this->placeQueue[$playerName])) {
			$this->placeQueue[$playerName] = [];
		}
		$this->placeQueue[$playerName]["pos1"] = $pos;
		$player->sendMessage("§aPosition 1 set to: " . $pos->getFloorX() . ", " . $pos->getFloorY() . ", " . $pos->getFloorZ());
	}

	public function setPos2(Player $player, Position $pos): void {
		$playerName = strtolower($player->getName());
		if(!isset($this->placeQueue[$playerName])) {
			$this->placeQueue[$playerName] = [];
		}
		$this->placeQueue[$playerName]["pos2"] = $pos;
		$player->sendMessage("§aPosition 2 set to: " . $pos->getFloorX() . ", " . $pos->getFloorY() . ", " . $pos->getFloorZ());
	}

	public function touchPos(Player $player, Position $pos): void {
		$property = $this->getPropertyAt($pos);
		if($property !== null) {
			$player->sendMessage("§aProperty owner: " . $property["owner"]);
			$player->sendMessage("§aProperty price: " . $property["price"]);
		} else {
			$player->sendMessage("§cNo property found at this location.");
		}
	}

	public function createProperty(Player $player, float $price): bool {
		$playerName = strtolower($player->getName());
		
		if(!isset($this->placeQueue[$playerName]["pos1"]) || !isset($this->placeQueue[$playerName]["pos2"])) {
			$player->sendMessage("§cYou must set both positions first!");
			return false;
		}

		$pos1 = $this->placeQueue[$playerName]["pos1"];
		$pos2 = $this->placeQueue[$playerName]["pos2"];

		if($pos1->getWorld()->getFolderName() !== $pos2->getWorld()->getFolderName()) {
			$player->sendMessage("§cBoth positions must be in the same world!");
			return false;
		}

		$minX = min($pos1->getFloorX(), $pos2->getFloorX());
		$maxX = max($pos1->getFloorX(), $pos2->getFloorX());
		$minY = min($pos1->getFloorY(), $pos2->getFloorY());
		$maxY = max($pos1->getFloorY(), $pos2->getFloorY());
		$minZ = min($pos1->getFloorZ(), $pos2->getFloorZ());
		$maxZ = max($pos1->getFloorZ(), $pos2->getFloorZ());

		$stmt = $this->property->prepare("INSERT INTO property (owner, price, startX, endX, startY, endY, startZ, endZ, world) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
		$stmt->bindValue(1, $playerName);
		$stmt->bindValue(2, $price);
		$stmt->bindValue(3, $minX);
		$stmt->bindValue(4, $maxX);
		$stmt->bindValue(5, $minY);
		$stmt->bindValue(6, $maxY);
		$stmt->bindValue(7, $minZ);
		$stmt->bindValue(8, $maxZ);
		$stmt->bindValue(9, $pos1->getWorld()->getFolderName());

		$result = $stmt->execute();
		$stmt->close();

		if($result) {
			unset($this->placeQueue[$playerName]);
			$player->sendMessage("§aProperty created successfully!");
			return true;
		}

		return false;
	}

	public function isProtected(Position $pos): bool {
		return $this->getPropertyAt($pos) !== null;
	}

	public function canUse(Player $player, Position $pos): bool {
		$property = $this->getPropertyAt($pos);
		if($property === null) {
			return true;
		}

		return $property["owner"] === strtolower($player->getName()) || $player->hasPermission("economyproperty.admin");
	}

	public function getPropertyAt(Position $pos): ?array {
		$stmt = $this->property->prepare("SELECT * FROM property WHERE ? BETWEEN startX AND endX AND ? BETWEEN startY AND endY AND ? BETWEEN startZ AND endZ AND world = ?");
		$stmt->bindValue(1, $pos->getFloorX());
		$stmt->bindValue(2, $pos->getFloorY());
		$stmt->bindValue(3, $pos->getFloorZ());
		$stmt->bindValue(4, $pos->getWorld()->getFolderName());

		$result = $stmt->execute();
		$data = $result->fetchArray(SQLITE3_ASSOC);
		$stmt->close();

		return $data ?: null;
	}

	public function setTapMode(Player $player, string $mode): void {
		$this->tap[strtolower($player->getName())] = $mode;
	}

	private function parseOldData(): void {
		// Migration logic for old data format if needed
	}
}