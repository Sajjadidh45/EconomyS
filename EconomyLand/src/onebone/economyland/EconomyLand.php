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

namespace onebone\economyland;

use onebone\economyapi\EconomyAPI;
use onebone\economyland\command\LandCommand;
use onebone\economyland\land\LandManager;
use onebone\economyland\provider\Provider;
use onebone\economyland\provider\YamlProvider;
use onebone\economyland\task\LandUnloadTask;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\world\World;

class EconomyLand extends PluginBase implements Listener {
	/** @var EconomyAPI */
	private $api;
	/** @var Provider */
	private $provider;
	/** @var LandManager */
	private $landManager;
	/** @var PluginConfiguration */
	private $pluginConfig;
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
		$this->saveResource("lang_en.json");

		$this->pluginConfig = new PluginConfiguration($this->getConfig());
		$this->lang = new Config($this->getDataFolder() . "lang_en.json", Config::JSON);

		$this->provider = new YamlProvider($this->getDataFolder() . "lands.yml");
		$this->landManager = new LandManager($this, $this->provider);

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);

		$this->getServer()->getCommandMap()->register("economyland", new LandCommand($this));

		// Start land unload task
		$this->getScheduler()->scheduleRepeatingTask(new LandUnloadTask($this->landManager), 20 * 60); // Every minute

		$this->getLogger()->info("EconomyLand has been enabled");
	}

	public function onDisable(): void {
		if($this->provider !== null) {
			$this->provider->save();
			$this->provider->close();
		}
	}

	public function getAPI(): EconomyAPI {
		return $this->api;
	}

	public function getProvider(): Provider {
		return $this->provider;
	}

	public function getLandManager(): LandManager {
		return $this->landManager;
	}

	public function getPluginConfig(): PluginConfiguration {
		return $this->pluginConfig;
	}

	public function getMessage(string $key, array $params = []): string {
		$messages = $this->lang->getAll();
		$message = $messages[$key] ?? $key;
		
		foreach($params as $i => $param) {
			$message = str_replace("{%" . ($i + 1) . "}", $param, $message);
		}
		
		return $message;
	}
}