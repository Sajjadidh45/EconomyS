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
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

class EconomyLand extends PluginBase {
	/** @var EconomyAPI */
	private $api;
	/** @var LandManager */
	private $landManager;
	/** @var Provider */
	private $provider;
	/** @var PluginConfiguration */
	private $pluginConfig;
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
		$this->saveResource("lang_en.json");

		$this->pluginConfig = new PluginConfiguration($this);
		$this->provider = new YamlProvider($this);
		$this->landManager = new LandManager($this, $this->provider);

		$langFile = $this->getDataFolder() . "lang_" . $this->pluginConfig->getLanguage() . ".json";
		if(!file_exists($langFile)) {
			$langFile = $this->getDataFolder() . "lang_en.json";
		}
		$this->lang = new Config($langFile, Config::JSON);

		$this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
		$this->getServer()->getCommandMap()->register("economyland", new LandCommand($this));

		$this->getScheduler()->scheduleRepeatingTask(
			new LandUnloadTask($this->landManager),
			$this->pluginConfig->getLandUnloadTaskPeriod()
		);
	}

	public function getAPI(): EconomyAPI {
		return $this->api;
	}

	public function getLandManager(): LandManager {
		return $this->landManager;
	}

	public function getProvider(): Provider {
		return $this->provider;
	}

	public function getPluginConfig(): PluginConfiguration {
		return $this->pluginConfig;
	}

	public function getMessage(string $key, array $params = []): string {
		$message = $this->lang->getNested($key, $key);
		
		foreach($params as $i => $param) {
			$message = str_replace("{%" . ($i + 1) . "}", $param, $message);
		}
		
		return $message;
	}

	public function onDisable() {
		if($this->provider !== null) {
			$this->provider->save();
			$this->provider->close();
		}
	}
}
