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

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;

class PropertyCommand extends Command {
	private $plugin;
	private $pos1, $pos2, $make, $touchPos;
	private $mergeData = [];

	public function __construct(EconomyProperty $plugin, $name, $pos1, $pos2, $make, $touchPos) {
		parent::__construct($name);
		$this->plugin = $plugin;
		$this->pos1 = $pos1;
		$this->pos2 = $pos2;
		$this->make = $make;
		$this->touchPos = $touchPos;
		$this->setPermission("economyproperty.command.property");
	}

	public function execute(CommandSender $sender, string $label, array $params): bool {
		if(!$this->testPermission($sender)) {
			return false;
		}

		if(!$sender instanceof Player) {
			$sender->sendMessage(TextFormat::RED . "Please run this command in-game.");
			return false;
		}

		if(!isset($params[0])) {
			$sender->sendMessage(TextFormat::RED . "Usage: /" . $this->getName() . " <pos1|pos2|make|touchpos>");
			return false;
		}

		switch(strtolower($params[0])) {
			case $this->pos1:
				$pos = $sender->getPosition();
				$this->mergePosition($sender->getName(), 0, [(int) $pos->getX(), (int) $pos->getZ(), $pos->getWorld()->getFolderName()]);
				$sender->sendMessage("[EconomyProperty] First position has been saved.");
				return true;

			case $this->pos2:
				$pos = $sender->getPosition();
				$this->mergePosition($sender->getName(), 1, [(int) $pos->getX(), (int) $pos->getZ(), $pos->getWorld()->getFolderName()]);
				$sender->sendMessage("[EconomyProperty] Second position has been saved.");
				return true;

			case $this->make:
				if(!isset($params[1]) or !is_numeric($params[1])) {
					$sender->sendMessage(TextFormat::RED . "Usage: /" . $this->getName() . " " . $this->make . " <price>");
					return false;
				}

				if(!isset($this->mergeData[$sender->getName()][0]) or !isset($this->mergeData[$sender->getName()][1])) {
					$sender->sendMessage(TextFormat::RED . "Please set both positions first.");
					return false;
				}

				$pos1 = $this->mergeData[$sender->getName()][0];
				$pos2 = $this->mergeData[$sender->getName()][1];

				if($pos1[2] !== $pos2[2]) {
					$sender->sendMessage(TextFormat::RED . "Both positions must be in the same world.");
					return false;
				}

				$startX = min($pos1[0], $pos2[0]);
				$endX = max($pos1[0], $pos2[0]);
				$startZ = min($pos1[1], $pos2[1]);
				$endZ = max($pos1[1], $pos2[1]);

				$price = (float) $params[1];

				$this->plugin->getProperty()->exec("INSERT INTO Property (startX, endX, startZ, endZ, level, owner, price) VALUES ($startX, $endX, $startZ, $endZ, '{$pos1[2]}', '{$sender->getName()}', $price)");
				$sender->sendMessage(TextFormat::GREEN . "Property created successfully!");

				unset($this->mergeData[$sender->getName()]);
				return true;

			case $this->touchPos:
				if(isset($this->plugin->touch[$sender->getName()])) {
					unset($this->plugin->touch[$sender->getName()]);
					$sender->sendMessage("[EconomyProperty] Touch position mode disabled.");
				} else {
					$this->plugin->touch[$sender->getName()] = true;
					$sender->sendMessage("[EconomyProperty] Touch position mode enabled. Touch a block to set first position.");
				}
				return true;
		}

		return false;
	}

	public function mergePosition(string $player, int $pos, array $data) {
		if(!isset($this->mergeData[$player])) {
			$this->mergeData[$player] = [];
		}
		$this->mergeData[$player][$pos] = $data;
	}

	public function getPlugin(): Plugin {
		return $this->plugin;
	}
}
