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
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

class EconomyCasino extends PluginBase implements Listener {
	/** @var EconomyAPI */
	private $api;
	/** @var array */
	private $games = [];

	public function onEnable(): void {
		$this->api = EconomyAPI::getInstance();
		if($this->api === null) {
			$this->getLogger()->critical("EconomyAPI plugin not found!");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		$this->saveDefaultConfig();
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->getLogger()->info("EconomyCasino has been enabled");
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		if($command->getName() === "casino") {
			if(!$sender instanceof Player) {
				$sender->sendMessage(TextFormat::RED . "Please run this command in-game.");
				return true;
			}

			if(!isset($args[0])) {
				$sender->sendMessage(TextFormat::RED . "Usage: /casino <dice|coin|lottery> <bet_amount>");
				return true;
			}

			$game = strtolower($args[0]);
			$betAmount = isset($args[1]) ? (float) $args[1] : 0;

			if($betAmount <= 0) {
				$sender->sendMessage(TextFormat::RED . "Bet amount must be positive!");
				return true;
			}

			if($this->api->myMoney($sender) < $betAmount) {
				$sender->sendMessage(TextFormat::RED . "You don't have enough money to place this bet!");
				return true;
			}

			switch($game) {
				case "dice":
					$this->playDice($sender, $betAmount);
					break;

				case "coin":
					$this->playCoinFlip($sender, $betAmount);
					break;

				case "lottery":
					$this->playLottery($sender, $betAmount);
					break;

				default:
					$sender->sendMessage(TextFormat::RED . "Available games: dice, coin, lottery");
					break;
			}

			return true;
		}

		return false;
	}

	private function playDice(Player $player, float $betAmount): void {
		$this->api->reduceMoney($player, $betAmount);

		$playerRoll = mt_rand(1, 6);
		$houseRoll = mt_rand(1, 6);

		$player->sendMessage(TextFormat::YELLOW . "=== DICE GAME ===");
		$player->sendMessage(TextFormat::AQUA . "Your roll: " . $playerRoll);
		$player->sendMessage(TextFormat::AQUA . "House roll: " . $houseRoll);

		if($playerRoll > $houseRoll) {
			$winnings = $betAmount * 1.8;
			$this->api->addMoney($player, $winnings);
			$player->sendMessage(TextFormat::GREEN . "You won! Winnings: $" . $winnings);
		} elseif($playerRoll === $houseRoll) {
			$this->api->addMoney($player, $betAmount);
			$player->sendMessage(TextFormat::YELLOW . "It's a tie! Your bet has been returned.");
		} else {
			$player->sendMessage(TextFormat::RED . "You lost! Better luck next time.");
		}
	}

	private function playCoinFlip(Player $player, float $betAmount): void {
		$this->api->reduceMoney($player, $betAmount);

		$playerChoice = mt_rand(0, 1); // 0 = heads, 1 = tails
		$result = mt_rand(0, 1);

		$choiceText = $playerChoice === 0 ? "Heads" : "Tails";
		$resultText = $result === 0 ? "Heads" : "Tails";

		$player->sendMessage(TextFormat::YELLOW . "=== COIN FLIP ===");
		$player->sendMessage(TextFormat::AQUA . "Your choice: " . $choiceText);
		$player->sendMessage(TextFormat::AQUA . "Result: " . $resultText);

		if($playerChoice === $result) {
			$winnings = $betAmount * 2;
			$this->api->addMoney($player, $winnings);
			$player->sendMessage(TextFormat::GREEN . "You won! Winnings: $" . $winnings);
		} else {
			$player->sendMessage(TextFormat::RED . "You lost! Better luck next time.");
		}
	}

	private function playLottery(Player $player, float $betAmount): void {
		$this->api->reduceMoney($player, $betAmount);

		$playerNumbers = [];
		$winningNumbers = [];

		// Generate player numbers
		for($i = 0; $i < 3; $i++) {
			$playerNumbers[] = mt_rand(1, 10);
		}

		// Generate winning numbers
		for($i = 0; $i < 3; $i++) {
			$winningNumbers[] = mt_rand(1, 10);
		}

		$matches = count(array_intersect($playerNumbers, $winningNumbers));

		$player->sendMessage(TextFormat::YELLOW . "=== LOTTERY ===");
		$player->sendMessage(TextFormat::AQUA . "Your numbers: " . implode(", ", $playerNumbers));
		$player->sendMessage(TextFormat::AQUA . "Winning numbers: " . implode(", ", $winningNumbers));
		$player->sendMessage(TextFormat::AQUA . "Matches: " . $matches);

		switch($matches) {
			case 3:
				$winnings = $betAmount * 10;
				$this->api->addMoney($player, $winnings);
				$player->sendMessage(TextFormat::GREEN . "JACKPOT! All numbers match! Winnings: $" . $winnings);
				break;

			case 2:
				$winnings = $betAmount * 3;
				$this->api->addMoney($player, $winnings);
				$player->sendMessage(TextFormat::GREEN . "Great! 2 numbers match! Winnings: $" . $winnings);
				break;

			case 1:
				$winnings = $betAmount * 1.5;
				$this->api->addMoney($player, $winnings);
				$player->sendMessage(TextFormat::GREEN . "Good! 1 number matches! Winnings: $" . $winnings);
				break;

			default:
				$player->sendMessage(TextFormat::RED . "No matches! Better luck next time.");
				break;
		}
	}
}