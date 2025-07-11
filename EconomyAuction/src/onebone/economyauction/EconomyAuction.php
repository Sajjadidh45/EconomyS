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

namespace onebone\economyauction;

use onebone\economyapi\EconomyAPI;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class EconomyAuction extends PluginBase implements Listener {
	/** @var EconomyAPI */
	private $api;
	/** @var array */
	private $auctions = [];
	/** @var Config */
	private $config;

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
		$this->saveResource("config.yml");

		$this->config = $this->getConfig();

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->getLogger()->info("EconomyAuction has been enabled");
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		if($command->getName() === "auction") {
			if(!$sender instanceof Player) {
				$sender->sendMessage(TextFormat::RED . "Please run this command in-game.");
				return true;
			}

			if(!isset($args[0])) {
				$sender->sendMessage(TextFormat::RED . "Usage: /auction <start|bid|list|end>");
				return true;
			}

			$subCommand = strtolower($args[0]);

			switch($subCommand) {
				case "start":
					if(!$sender->hasPermission("economyauction.start")) {
						$sender->sendMessage(TextFormat::RED . "You don't have permission to start auctions.");
						return true;
					}

					if(!isset($args[1]) || !isset($args[2])) {
						$sender->sendMessage(TextFormat::RED . "Usage: /auction start <starting_price> <duration_minutes>");
						return true;
					}

					$startingPrice = (float) $args[1];
					$duration = (int) $args[2];

					if($startingPrice <= 0) {
						$sender->sendMessage(TextFormat::RED . "Starting price must be positive.");
						return true;
					}

					if($duration <= 0 || $duration > 60) {
						$sender->sendMessage(TextFormat::RED . "Duration must be between 1 and 60 minutes.");
						return true;
					}

					$this->startAuction($sender, $startingPrice, $duration);
					break;

				case "bid":
					if(!$sender->hasPermission("economyauction.bid")) {
						$sender->sendMessage(TextFormat::RED . "You don't have permission to bid.");
						return true;
					}

					if(!isset($args[1])) {
						$sender->sendMessage(TextFormat::RED . "Usage: /auction bid <amount>");
						return true;
					}

					$bidAmount = (float) $args[1];

					if($bidAmount <= 0) {
						$sender->sendMessage(TextFormat::RED . "Bid amount must be positive.");
						return true;
					}

					$this->placeBid($sender, $bidAmount);
					break;

				case "list":
					$this->showAuctions($sender);
					break;

				case "end":
					if(!$sender->hasPermission("economyauction.admin")) {
						$sender->sendMessage(TextFormat::RED . "You don't have permission to end auctions.");
						return true;
					}

					$this->endCurrentAuction($sender);
					break;

				default:
					$sender->sendMessage(TextFormat::RED . "Usage: /auction <start|bid|list|end>");
					break;
			}

			return true;
		}

		return false;
	}

	private function startAuction(Player $player, float $startingPrice, int $duration): void {
		if(!empty($this->auctions)) {
			$player->sendMessage(TextFormat::RED . "An auction is already in progress!");
			return;
		}

		$item = $player->getInventory()->getItemInHand();
		if($item->isNull() || $item->getCount() === 0) {
			$player->sendMessage(TextFormat::RED . "You must hold an item to auction!");
			return;
		}

		$auctionId = uniqid();
		$endTime = time() + ($duration * 60);

		$this->auctions[$auctionId] = [
			"seller" => $player->getName(),
			"item" => $item,
			"starting_price" => $startingPrice,
			"current_bid" => $startingPrice,
			"highest_bidder" => null,
			"end_time" => $endTime,
			"duration" => $duration
		];

		$player->getInventory()->setItemInHand($item->setCount(0));

		$this->getScheduler()->scheduleDelayedTask(new QuitAuctionTask($this, $auctionId), $duration * 60 * 20);

		$this->broadcastMessage(TextFormat::GREEN . "New auction started by " . $player->getName() . "!");
		$this->broadcastMessage(TextFormat::YELLOW . "Item: " . $item->getName() . " x" . $item->getCount());
		$this->broadcastMessage(TextFormat::YELLOW . "Starting price: $" . $startingPrice);
		$this->broadcastMessage(TextFormat::YELLOW . "Duration: " . $duration . " minutes");
		$this->broadcastMessage(TextFormat::AQUA . "Use /auction bid <amount> to place a bid!");
	}

	private function placeBid(Player $player, float $bidAmount): void {
		if(empty($this->auctions)) {
			$player->sendMessage(TextFormat::RED . "No auction is currently active!");
			return;
		}

		$auction = reset($this->auctions);
		$auctionId = key($this->auctions);

		if($auction["seller"] === $player->getName()) {
			$player->sendMessage(TextFormat::RED . "You cannot bid on your own auction!");
			return;
		}

		if($bidAmount <= $auction["current_bid"]) {
			$player->sendMessage(TextFormat::RED . "Your bid must be higher than the current bid of $" . $auction["current_bid"]);
			return;
		}

		if($this->api->myMoney($player) < $bidAmount) {
			$player->sendMessage(TextFormat::RED . "You don't have enough money to place this bid!");
			return;
		}

		// Return money to previous highest bidder
		if($auction["highest_bidder"] !== null) {
			$previousBidder = $this->getServer()->getPlayerExact($auction["highest_bidder"]);
			if($previousBidder !== null) {
				$this->api->addMoney($previousBidder, $auction["current_bid"]);
				$previousBidder->sendMessage(TextFormat::YELLOW . "You have been outbid! Your money has been returned.");
			}
		}

		// Take money from new bidder
		$this->api->reduceMoney($player, $bidAmount);

		$this->auctions[$auctionId]["current_bid"] = $bidAmount;
		$this->auctions[$auctionId]["highest_bidder"] = $player->getName();

		$this->broadcastMessage(TextFormat::GREEN . $player->getName() . " placed a bid of $" . $bidAmount . "!");
	}

	private function showAuctions(Player $player): void {
		if(empty($this->auctions)) {
			$player->sendMessage(TextFormat::YELLOW . "No auctions are currently active.");
			return;
		}

		$auction = reset($this->auctions);

		$timeLeft = $auction["end_time"] - time();
		$minutesLeft = ceil($timeLeft / 60);

		$player->sendMessage(TextFormat::GREEN . "=== Current Auction ===");
		$player->sendMessage(TextFormat::YELLOW . "Seller: " . $auction["seller"]);
		$player->sendMessage(TextFormat::YELLOW . "Item: " . $auction["item"]->getName() . " x" . $auction["item"]->getCount());
		$player->sendMessage(TextFormat::YELLOW . "Current bid: $" . $auction["current_bid"]);
		$player->sendMessage(TextFormat::YELLOW . "Highest bidder: " . ($auction["highest_bidder"] ?? "None"));
		$player->sendMessage(TextFormat::YELLOW . "Time left: " . $minutesLeft . " minutes");
	}

	private function endCurrentAuction(?Player $admin = null): void {
		if(empty($this->auctions)) {
			if($admin !== null) {
				$admin->sendMessage(TextFormat::RED . "No auction is currently active!");
			}
			return;
		}

		$auction = reset($this->auctions);
		$auctionId = key($this->auctions);

		$this->endAuction($auctionId);
	}

	public function endAuction(string $auctionId): void {
		if(!isset($this->auctions[$auctionId])) {
			return;
		}

		$auction = $this->auctions[$auctionId];

		if($auction["highest_bidder"] !== null) {
			// Give money to seller
			$seller = $this->getServer()->getPlayerExact($auction["seller"]);
			if($seller !== null) {
				$this->api->addMoney($seller, $auction["current_bid"]);
				$seller->sendMessage(TextFormat::GREEN . "Your auction ended! You received $" . $auction["current_bid"]);
			}

			// Give item to winner
			$winner = $this->getServer()->getPlayerExact($auction["highest_bidder"]);
			if($winner !== null) {
				$winner->getInventory()->addItem($auction["item"]);
				$winner->sendMessage(TextFormat::GREEN . "Congratulations! You won the auction for $" . $auction["current_bid"]);
			}

			$this->broadcastMessage(TextFormat::GREEN . "Auction ended! Winner: " . $auction["highest_bidder"] . " for $" . $auction["current_bid"]);
		} else {
			// No bids, return item to seller
			$seller = $this->getServer()->getPlayerExact($auction["seller"]);
			if($seller !== null) {
				$seller->getInventory()->addItem($auction["item"]);
				$seller->sendMessage(TextFormat::YELLOW . "Your auction ended with no bids. Item returned.");
			}

			$this->broadcastMessage(TextFormat::YELLOW . "Auction ended with no bids.");
		}

		unset($this->auctions[$auctionId]);
	}

	private function broadcastMessage(string $message): void {
		$this->getServer()->broadcastMessage($message);
	}
}