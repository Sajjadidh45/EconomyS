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

namespace onebone\economycasino;

use onebone\economyapi\EconomyAPI;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

class EconomyCasino extends PluginBase implements Listener {
	private $casino;
	/**
	 * @var EconomyAPI
	 */
	private $api;
	/**
	 * @var Config
	 */
	private $config;

	public function onEnable() {
		@mkdir($this->getDataFolder());
		$this->api = EconomyAPI::getInstance();
		$this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, array(
				"jackpot-winning" => 1000,
				"jackpot-money" => 5,
				"max-game" => 10
		));

		$this->casino = array();

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	public function onDisable() {
		$this->api = null;
		$this->casino = array();
	}

	public function onQuitEvent(PlayerQuitEvent $event) {
		$player = $event->getPlayer();

		foreach($this->casino as $pl => $casino) {
			if(isset($casino["players"][$pl])) {
				unset($this->casino[$pl]["players"][$pl]);
				$players = $this->casino[$pl]["players"];

				$name = $player->getName();
				foreach($players as $p => $v) {
					$this->getServer()->getPlayerExact($p)->sendMessage("[EconomyCasino] " . $name . " left the casino game.");
				}
				break;
			}
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		if($command->getName() === "casino") {
			if(!$sender instanceof Player) {
				$sender->sendMessage("Please run this command in-game.");
				return false;
			}

			if(!isset($args[0])) {
				$sender->sendMessage("Usage: /casino <start|join|quit>");
				return false;
			}

			switch(strtolower($args[0])) {
				case "start":
					if(isset($this->casino[$sender->getName()])) {
						$sender->sendMessage("[EconomyCasino] You are already in a casino game.");
						return false;
					}

					$this->casino[$sender->getName()] = [
						"host" => $sender->getName(),
						"players" => [$sender->getName() => true],
						"started" => false
					];

					$sender->sendMessage("[EconomyCasino] Casino game started! Other players can join with /casino join " . $sender->getName());
					return true;

				case "join":
					if(!isset($args[1])) {
						$sender->sendMessage("Usage: /casino join <host>");
						return false;
					}

					$host = $args[1];
					if(!isset($this->casino[$host])) {
						$sender->sendMessage("[EconomyCasino] Casino game not found.");
						return false;
					}

					if($this->casino[$host]["started"]) {
						$sender->sendMessage("[EconomyCasino] This game has already started.");
						return false;
					}

					if(count($this->casino[$host]["players"]) >= $this->config->get("max-game")) {
						$sender->sendMessage("[EconomyCasino] This game is full.");
						return false;
					}

					$this->casino[$host]["players"][$sender->getName()] = true;
					$sender->sendMessage("[EconomyCasino] You joined the casino game!");

					foreach($this->casino[$host]["players"] as $playerName => $v) {
						$player = $this->getServer()->getPlayerExact($playerName);
						if($player !== null) {
							$player->sendMessage("[EconomyCasino] " . $sender->getName() . " joined the game!");
						}
					}
					return true;

				case "quit":
					$found = false;
					foreach($this->casino as $host => $casino) {
						if(isset($casino["players"][$sender->getName()])) {
							unset($this->casino[$host]["players"][$sender->getName()]);
							$found = true;

							if(empty($this->casino[$host]["players"])) {
								unset($this->casino[$host]);
							} else {
								foreach($this->casino[$host]["players"] as $playerName => $v) {
									$player = $this->getServer()->getPlayerExact($playerName);
									if($player !== null) {
										$player->sendMessage("[EconomyCasino] " . $sender->getName() . " left the game!");
									}
								}
							}
							break;
						}
					}

					if($found) {
						$sender->sendMessage("[EconomyCasino] You left the casino game.");
					} else {
						$sender->sendMessage("[EconomyCasino] You are not in any casino game.");
					}
					return true;

				case "play":
					$found = false;
					foreach($this->casino as $host => $casino) {
						if(isset($casino["players"][$sender->getName()])) {
							$found = true;
							break;
						}
					}

					if(!$found) {
						$sender->sendMessage("[EconomyCasino] You are not in any casino game.");
						return false;
					}

					$bet = $this->config->get("jackpot-money");
					if($this->api->myMoney($sender) < $bet) {
						$sender->sendMessage("[EconomyCasino] You need at least $" . $bet . " to play.");
						return false;
					}

					$this->api->reduceMoney($sender, $bet);

					$random = mt_rand(1, $this->config->get("jackpot-winning"));
					if($random === 1) {
						$winning = $bet * count($this->casino[$host]["players"]) * 10;
						$this->api->addMoney($sender, $winning);
						$sender->sendMessage("[EconomyCasino] JACKPOT! You won $" . $winning . "!");
					} else {
						$sender->sendMessage("[EconomyCasino] You lost $" . $bet . ". Better luck next time!");
					}
					return true;
			}
		}
		return false;
	}
}
