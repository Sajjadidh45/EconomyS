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
use pocketmine\utils\TextFormat;

class PropertyCommand extends Command {
	/** @var EconomyProperty */
	private $plugin;
	/** @var string */
	private $pos1Command, $pos2Command, $makeCommand, $touchCommand;

	public function __construct(EconomyProperty $plugin, string $name, string $pos1, string $pos2, string $make, string $touch) {
		parent::__construct($name, "Property management command", "/$name <pos1|pos2|make|touch>");
		$this->plugin = $plugin;
		$this->pos1Command = $pos1;
		$this->pos2Command = $pos2;
		$this->makeCommand = $make;
		$this->touchCommand = $touch;
		$this->setPermission("economyproperty.command");
	}

	public function execute(CommandSender $sender, string $label, array $args): bool {
		if(!$this->testPermission($sender)) {
			return false;
		}

		if(!$sender instanceof Player) {
			$sender->sendMessage(TextFormat::RED . "Please run this command in-game.");
			return true;
		}

		if(!isset($args[0])) {
			$sender->sendMessage(TextFormat::RED . "Usage: /" . $label . " <pos1|pos2|make|touch>");
			return true;
		}

		switch(strtolower($args[0])) {
			case $this->pos1Command:
			case "pos1":
				$this->plugin->setTapMode($sender, "pos1");
				$sender->sendMessage(TextFormat::GREEN . "Tap a block to set position 1.");
				break;

			case $this->pos2Command:
			case "pos2":
				$this->plugin->setTapMode($sender, "pos2");
				$sender->sendMessage(TextFormat::GREEN . "Tap a block to set position 2.");
				break;

			case $this->makeCommand:
			case "make":
				if(!isset($args[1]) || !is_numeric($args[1])) {
					$sender->sendMessage(TextFormat::RED . "Usage: /" . $label . " make <price>");
					return true;
				}

				$price = (float) $args[1];
				if($price <= 0) {
					$sender->sendMessage(TextFormat::RED . "Price must be a positive number.");
					return true;
				}

				$this->plugin->createProperty($sender, $price);
				break;

			case $this->touchCommand:
			case "touch":
				$this->plugin->setTapMode($sender, "touchpos");
				$sender->sendMessage(TextFormat::GREEN . "Tap a block to check property information.");
				break;

			default:
				$sender->sendMessage(TextFormat::RED . "Usage: /" . $label . " <pos1|pos2|make|touch>");
				break;
		}

		return true;
	}
}