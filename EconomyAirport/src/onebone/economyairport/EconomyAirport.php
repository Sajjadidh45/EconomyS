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

namespace onebone\economyairport;

use onebone\economyapi\EconomyAPI;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;

class EconomyAirport extends PluginBase implements Listener {
	/** @var EconomyAPI */
	private $api;
	/** @var Config */
	private $airports;
	/** @var Config */
	private $lang;

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

		$this->saveDefaultConfig();
		$this->saveResource("airport.yml");
		$this->saveResource("language.properties");

		$this->airports = new Config($this->getDataFolder() . "airport.yml", Config::YAML);
		$this->lang = new Config($this->getDataFolder() . "language.properties", Config::PROPERTIES);

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->getLogger()->info("EconomyAirport has been enabled");
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		if($command->getName() === "airport") {
			if(!$sender instanceof Player) {
				$sender->sendMessage(TextFormat::RED . "Please run this command in-game.");
				return true;
			}

			if(!isset($args[0])) {
				$this->showAirportList($sender);
				return true;
			}

			$subCommand = strtolower($args[0]);

			switch($subCommand) {
				case "add":
					if(!$sender->hasPermission("economyairport.admin")) {
						$sender->sendMessage(TextFormat::RED . "You don't have permission to add airports.");
						return true;
					}

					if(!isset($args[1]) || !isset($args[2])) {
						$sender->sendMessage(TextFormat::RED . "Usage: /airport add <name> <price>");
						return true;
					}

					$name = $args[1];
					$price = (float) $args[2];

					if($price < 0) {
						$sender->sendMessage(TextFormat::RED . "Price cannot be negative.");
						return true;
					}

					$this->addAirport($sender, $name, $price);
					break;

				case "remove":
					if(!$sender->hasPermission("economyairport.admin")) {
						$sender->sendMessage(TextFormat::RED . "You don't have permission to remove airports.");
						return true;
					}

					if(!isset($args[1])) {
						$sender->sendMessage(TextFormat::RED . "Usage: /airport remove <name>");
						return true;
					}

					$name = $args[1];
					$this->removeAirport($sender, $name);
					break;

				case "list":
					$this->showAirportList($sender);
					break;

				default:
					// Try to teleport to airport
					$airportName = $args[0];
					$this->teleportToAirport($sender, $airportName);
					break;
			}

			return true;
		}

		return false;
	}

	private function addAirport(Player $player, string $name, float $price): void {
		$airports = $this->airports->getAll();

		if(isset($airports[$name])) {
			$player->sendMessage(TextFormat::RED . $this->getMessage("airport-exists", [$name]));
			return;
		}

		$pos = $player->getPosition();
		$airports[$name] = [
			"x" => $pos->getFloorX(),
			"y" => $pos->getFloorY(),
			"z" => $pos->getFloorZ(),
			"world" => $pos->getWorld()->getFolderName(),
			"price" => $price
		];

		$this->airports->setAll($airports);
		$this->airports->save();

		$player->sendMessage(TextFormat::GREEN . $this->getMessage("airport-added", [$name, $price]));
	}

	private function removeAirport(Player $player, string $name): void {
		$airports = $this->airports->getAll();

		if(!isset($airports[$name])) {
			$player->sendMessage(TextFormat::RED . $this->getMessage("airport-not-exists", [$name]));
			return;
		}

		unset($airports[$name]);
		$this->airports->setAll($airports);
		$this->airports->save();

		$player->sendMessage(TextFormat::GREEN . $this->getMessage("airport-removed", [$name]));
	}

	private function showAirportList(Player $player): void {
		$airports = $this->airports->getAll();

		if(empty($airports)) {
			$player->sendMessage(TextFormat::YELLOW . $this->getMessage("no-airports"));
			return;
		}

		$player->sendMessage(TextFormat::GREEN . $this->getMessage("airport-list-header"));

		foreach($airports as $name => $data) {
			$price = $data["price"];
			$world = $data["world"];
			$player->sendMessage(TextFormat::AQUA . "- " . $name . TextFormat::WHITE . " (" . $world . ") - " . TextFormat::GOLD . "$" . $price);
		}
	}

	private function teleportToAirport(Player $player, string $airportName): void {
		$airports = $this->airports->getAll();

		if(!isset($airports[$airportName])) {
			$player->sendMessage(TextFormat::RED . $this->getMessage("airport-not-exists", [$airportName]));
			return;
		}

		$airport = $airports[$airportName];
		$price = $airport["price"];

		if($this->api->myMoney($player) < $price) {
			$player->sendMessage(TextFormat::RED . $this->getMessage("not-enough-money", [$price]));
			return;
		}

		$world = $this->getServer()->getWorldManager()->getWorldByName($airport["world"]);
		if($world === null) {
			$player->sendMessage(TextFormat::RED . $this->getMessage("world-not-found", [$airport["world"]]));
			return;
		}

		$pos = new Position($airport["x"], $airport["y"], $airport["z"], $world);

		if($price > 0) {
			$result = $this->api->reduceMoney($player, $price);
			if($result !== EconomyAPI::RET_SUCCESS) {
				$player->sendMessage(TextFormat::RED . $this->getMessage("transaction-failed"));
				return;
			}
		}

		$player->teleport($pos);
		$player->sendMessage(TextFormat::GREEN . $this->getMessage("teleported-to-airport", [$airportName, $price]));
	}

	private function getMessage(string $key, array $params = []): string {
		$message = $this->lang->get($key, $key);

		foreach($params as $i => $param) {
			$message = str_replace("{%" . ($i + 1) . "}", $param, $message);
		}

		return $message;
	}
}