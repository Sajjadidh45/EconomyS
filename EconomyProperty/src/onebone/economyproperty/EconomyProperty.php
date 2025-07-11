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
use onebone\economyland\EconomyLand;
use onebone\economyland\land\Land;
use onebone\economyland\land\LandMeta;
use onebone\economyland\land\LandOption;
use pocketmine\block\Air;
use pocketmine\block\Block;
use pocketmine\block\tile\Sign;
use pocketmine\block\tile\Tile;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
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

	public function onEnable() {
		if(!file_exists($this->getDataFolder())) {
			mkdir($this->getDataFolder());
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
	}

	public function onBlockTouch(PlayerInteractEvent $event) {
		if($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
			return;
		}

		$block = $event->getBlock();
		$player = $event->getPlayer();

		if(isset($this->touch[$player->getName()])) {
			$this->command->mergePosition($player->getName(), 0, [(int) $block->getX(), (int) $block->getZ(), $block->getPosition()->getWorld()->getFolderName()]);
			$player->sendMessage("[EconomyProperty] First position has been saved.");
			$event->cancel();
			if($event->getItem()->canBePlaced()) {
				$this->placeQueue[$player->getName()] = true;
			}
			return;
		}

		$info = $this->property->query("SELECT * FROM Property WHERE startX <= {$block->getX()} AND landX >= {$block->getX()} AND startZ <= {$block->getZ()} AND landZ >= {$block->getZ()} AND level = '{$block->getPosition()->getWorld()->getFolderName()}'")->fetchArray(SQLITE3_ASSOC);
		if($info !== false) {
			if($info["owner"] === $player->getName()) {
				$player->sendMessage("[EconomyProperty] This is your property.");
			} else {
				$player->sendMessage("[EconomyProperty] This property is owned by " . $info["owner"]);
			}
		}
	}

	public function onBlockPlace(BlockPlaceEvent $event) {
		$player = $event->getPlayer();
		$block = $event->getBlock();

		if(isset($this->placeQueue[$player->getName()])) {
			unset($this->placeQueue[$player->getName()]);
			return;
		}

		$info = $this->property->query("SELECT * FROM Property WHERE startX <= {$block->getX()} AND landX >= {$block->getX()} AND startZ <= {$block->getZ()} AND landZ >= {$block->getZ()} AND level = '{$block->getPosition()->getWorld()->getFolderName()}'")->fetchArray(SQLITE3_ASSOC);
		if($info !== false) {
			if($info["owner"] !== $player->getName()) {
				$event->cancel();
				$player->sendMessage("[EconomyProperty] You cannot place blocks in " . $info["owner"] . "'s property.");
			}
		}
	}

	public function onBlockBreak(BlockBreakEvent $event) {
		$player = $event->getPlayer();
		$block = $event->getBlock();

		$info = $this->property->query("SELECT * FROM Property WHERE startX <= {$block->getX()} AND landX >= {$block->getX()} AND startZ <= {$block->getZ()} AND landZ >= {$block->getZ()} AND level = '{$block->getPosition()->getWorld()->getFolderName()}'")->fetchArray(SQLITE3_ASSOC);
		if($info !== false) {
			if($info["owner"] !== $player->getName()) {
				$event->cancel();
				$player->sendMessage("[EconomyProperty] You cannot break blocks in " . $info["owner"] . "'s property.");
			}
		}
	}

	public function parseOldData() {
		if(file_exists($this->getDataFolder() . "Property.yml")) {
			$config = new \pocketmine\utils\Config($this->getDataFolder() . "Property.yml", \pocketmine\utils\Config::YAML);
			foreach($config->getAll() as $data) {
				$this->property->exec("INSERT OR IGNORE INTO Property (startX, endX, startZ, endZ, level, owner, price) VALUES ({$data["startX"]}, {$data["endX"]}, {$data["startZ"]}, {$data["endZ"]}, '{$data["level"]}', '{$data["owner"]}', {$data["price"]})");
			}
			unlink($this->getDataFolder() . "Property.yml");
		}
	}

	public function getProperty() {
		return $this->property;
	}

	public function onDisable() {
		if($this->property instanceof SQLite3) {
			$this->property->close();
		}
	}
}
