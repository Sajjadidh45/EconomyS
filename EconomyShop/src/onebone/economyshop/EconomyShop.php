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

namespace onebone\economyshop;

use onebone\economyapi\EconomyAPI;
use onebone\economyshop\event\ShopCreationEvent;
use onebone\economyshop\event\ShopTransactionEvent;
use onebone\economyshop\item\ItemDisplayer;
use onebone\economyshop\provider\DataProvider;
use onebone\economyshop\provider\YamlDataProvider;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\Item;
use pocketmine\world\Position;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\item\StringToItemParser;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Config;
use pocketmine\block\tile\Sign;
use pocketmine\block\utils\SignText;

class EconomyShop extends PluginBase implements Listener {
	/** @var EconomyAPI */
	private $api;
	/** @var DataProvider */
	private $provider;
	/** @var ItemDisplayer[] */
	private $displayers = [];
	/** @var Config */
	private $lang;

	public function onEnable() {
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
		$this->saveResource("language.yml");
		$this->saveResource("ShopText.yml");

		$this->provider = new YamlDataProvider($this->getDataFolder() . "Shops.yml", true);

		$this->lang = new Config($this->getDataFolder() . "language.yml", Config::YAML);

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		foreach($this->provider->getAll() as $shop) {
			$pos = new Position($shop["x"], $shop["y"], $shop["z"], $this->getServer()->getWorldManager()->getWorldByName($shop["level"]));
			if($pos->getWorld() === null) continue;

			$item = StringToItemParser::getInstance()->parse($shop["item"]);
			if($item === null) continue;

			$this->displayers[] = new ItemDisplayer($pos, $item, $pos);
		}
	}

	public function onJoin(PlayerJoinEvent $event) {
		$player = $event->getPlayer();
		foreach($this->displayers as $displayer) {
			$displayer->spawnTo($player);
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		switch($command->getName()) {
			case "shop":
				if(!$sender instanceof Player) {
					$sender->sendMessage(TextFormat::RED . "Please run this command in-game.");
					return false;
				}

				if(!isset($args[0])) {
					$sender->sendMessage(TextFormat::RED . "Usage: /shop <create|remove>");
					return false;
				}

				switch(strtolower($args[0])) {
					case "create":
					case "c":
						if(!$sender->hasPermission("economyshop.command.shop.create")) {
							$sender->sendMessage(TextFormat::RED . "You don't have permission to create shops.");
							return false;
						}

						if(!isset($args[1]) or !isset($args[2]) or !isset($args[3])) {
							$sender->sendMessage(TextFormat::RED . "Usage: /shop create <item> <price> <amount>");
							return false;
						}

						$item = StringToItemParser::getInstance()->parse($args[1]);
						if($item === null) {
							$sender->sendMessage(TextFormat::RED . "Invalid item: " . $args[1]);
							return false;
						}

						if(!is_numeric($args[2]) or $args[2] < 0) {
							$sender->sendMessage(TextFormat::RED . "Price must be a positive number.");
							return false;
						}

						if(!is_numeric($args[3]) or $args[3] < 1) {
							$sender->sendMessage(TextFormat::RED . "Amount must be a positive number.");
							return false;
						}

						$price = (float) $args[2];
						$amount = (int) $args[3];

						$pos = $sender->getPosition();
						$shop = [
							"x" => $pos->getFloorX(),
							"y" => $pos->getFloorY(),
							"z" => $pos->getFloorZ(),
							"level" => $pos->getWorld()->getFolderName(),
							"item" => $args[1],
							"price" => $price,
							"amount" => $amount,
							"creator" => $sender->getName()
						];

						$event = new ShopCreationEvent($this, $shop, $sender);
						$event->call();

						if($event->isCancelled()) {
							return false;
						}

						$this->provider->addShop($pos, $shop);
						$this->displayers[] = new ItemDisplayer($pos, $item, $pos);

						$sender->sendMessage(TextFormat::GREEN . "Shop created successfully!");
						return true;

					case "remove":
					case "r":
						if(!$sender->hasPermission("economyshop.command.shop.remove")) {
							$sender->sendMessage(TextFormat::RED . "You don't have permission to remove shops.");
							return false;
						}

						$pos = $sender->getPosition();
						$shop = $this->provider->getShop($pos);

						if($shop === null) {
							$sender->sendMessage(TextFormat::RED . "No shop found at this location.");
							return false;
						}

						if($shop["creator"] !== $sender->getName() and !$sender->hasPermission("economyshop.admin")) {
							$sender->sendMessage(TextFormat::RED . "You can only remove your own shops.");
							return false;
						}

						$this->provider->removeShop($pos);
						$sender->sendMessage(TextFormat::GREEN . "Shop removed successfully!");
						return true;
				}
				break;
		}
		return false;
	}

	public function onInteract(PlayerInteractEvent $event) {
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$pos = $block->getPosition();

		$shop = $this->provider->getShop($pos);
		if($shop === null) return;

		$event->cancel();

		$item = StringToItemParser::getInstance()->parse($shop["item"]);
		if($item === null) return;

		$item->setCount($shop["amount"]);

		if($this->api->myMoney($player) < $shop["price"]) {
			$player->sendMessage(TextFormat::RED . "You don't have enough money!");
			return;
		}

		if(!$player->getInventory()->canAddItem($item)) {
			$player->sendMessage(TextFormat::RED . "Your inventory is full!");
			return;
		}

		$transactionEvent = new ShopTransactionEvent($this, $shop, $player, $item, $shop["price"]);
		$transactionEvent->call();

		if($transactionEvent->isCancelled()) {
			return;
		}

		$this->api->reduceMoney($player, $shop["price"]);
		$player->getInventory()->addItem($item);

		$player->sendMessage(TextFormat::GREEN . "You bought " . $item->getName() . " x" . $shop["amount"] . " for $" . $shop["price"]);
	}

	public function onBreak(BlockBreakEvent $event) {
		$pos = $event->getBlock()->getPosition();
		$shop = $this->provider->getShop($pos);

		if($shop !== null) {
			$player = $event->getPlayer();
			if($shop["creator"] !== $player->getName() and !$player->hasPermission("economyshop.admin")) {
				$event->cancel();
				$player->sendMessage(TextFormat::RED . "You cannot break this shop!");
			} else {
				$this->provider->removeShop($pos);
				$player->sendMessage(TextFormat::GREEN . "Shop removed!");
			}
		}
	}

	public function onDisable() {
		if($this->provider !== null) {
			$this->provider->save();
		}
	}
}
