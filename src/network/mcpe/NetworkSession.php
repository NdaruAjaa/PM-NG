<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pocketmine\block\tile\Spawnable;
use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\entity\Attribute;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\event\player\PlayerDuplicateLoginEvent;
use pocketmine\event\player\SessionDisconnectEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\form\Form;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\lang\Translatable;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\SkinAdapterSingleton;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\encryption\DecryptionException;
use pocketmine\network\mcpe\encryption\EncryptionContext;
use pocketmine\network\mcpe\encryption\PrepareEncryptionTask;
use pocketmine\network\mcpe\handler\DeathPacketHandler;
use pocketmine\network\mcpe\handler\HandshakePacketHandler;
use pocketmine\network\mcpe\handler\InGamePacketHandler;
use pocketmine\network\mcpe\handler\LoginPacketHandler;
use pocketmine\network\mcpe\handler\PacketHandler;
use pocketmine\network\mcpe\handler\PreSpawnPacketHandler;
use pocketmine\network\mcpe\handler\ResourcePacksPacketHandler;
use pocketmine\network\mcpe\handler\SessionStartPacketHandler;
use pocketmine\network\mcpe\handler\SpawnResponsePacketHandler;
use pocketmine\network\mcpe\protocol\AdventureSettingsPacket;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\ChunkRadiusUpdatedPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ClientCacheMissResponsePacket;
use pocketmine\network\mcpe\protocol\DisconnectPacket;
use pocketmine\network\mcpe\protocol\EmotePacket;
use pocketmine\network\mcpe\protocol\MobArmorEquipmentPacket;
use pocketmine\network\mcpe\protocol\MobEffectPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkChunkPublisherUpdatePacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\ServerToClientHandshakePacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\SetDifficultyPacket;
use pocketmine\network\mcpe\protocol\SetPlayerGameTypePacket;
use pocketmine\network\mcpe\protocol\SetSpawnPositionPacket;
use pocketmine\network\mcpe\protocol\SetTimePacket;
use pocketmine\network\mcpe\protocol\SetTitlePacket;
use pocketmine\network\mcpe\protocol\TakeItemActorPacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\ToastRequestPacket;
use pocketmine\network\mcpe\protocol\TransferPacket;
use pocketmine\network\mcpe\protocol\types\AbilitiesData;
use pocketmine\network\mcpe\protocol\types\AbilitiesLayer;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\ChunkCacheBlob;
use pocketmine\network\mcpe\protocol\types\command\CommandData;
use pocketmine\network\mcpe\protocol\types\command\CommandEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\types\entity\MetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\PlayerListAdditionEntries;
use pocketmine\network\mcpe\protocol\types\PlayerListAdditionEntry;
use pocketmine\network\mcpe\protocol\types\PlayerListRemovalEntries;
use pocketmine\network\mcpe\protocol\types\PlayerPermissions;
use pocketmine\network\mcpe\protocol\UpdateAbilitiesPacket;
use pocketmine\network\mcpe\protocol\UpdateAdventureSettingsPacket;
use pocketmine\network\mcpe\protocol\UpdateAttributesPacket;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\NetworkSessionManager;
use pocketmine\network\PacketHandlingException;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\player\PlayerInfo;
use pocketmine\player\UsedChunkStatus;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\ObjectSet;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use pocketmine\world\Position;
use function array_keys;
use function array_map;
use function array_replace;
use function array_values;
use function base64_encode;
use function bin2hex;
use function count;
use function get_class;
use function hrtime;
use function in_array;
use function intdiv;
use function json_encode;
use function ksort;
use function min;
use function random_bytes;
use function strcasecmp;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function time;
use function ucfirst;
use const JSON_THROW_ON_ERROR;
use const SORT_NUMERIC;

class NetworkSession{
	private const INCOMING_PACKET_BATCH_PER_TICK = 2; //usually max 1 per tick, but transactions may arrive separately
	private const INCOMING_PACKET_BATCH_MAX_BUDGET = 100 * self::INCOMING_PACKET_BATCH_PER_TICK; //enough to account for a 5-second lag spike

	/**
	 * At most this many more packets can be received. If this reaches zero, any additional packets received will cause
	 * the player to be kicked from the server.
	 * This number is increased every tick up to a maximum limit.
	 *
	 * @see self::INCOMING_PACKET_BATCH_PER_TICK
	 * @see self::INCOMING_PACKET_BATCH_MAX_BUDGET
	 */
	private int $incomingPacketBatchBudget = self::INCOMING_PACKET_BATCH_MAX_BUDGET;
	private int $lastPacketBudgetUpdateTimeNs;

	private \PrefixedLogger $logger;
	private ?Player $player = null;
	protected ?PlayerInfo $info = null;
	private ?int $ping = null;

	private ?PacketHandler $handler = null;

	private bool $connected = true;
	private bool $disconnectGuard = false;
	protected bool $loggedIn = false;
	private bool $authenticated = false;
	private int $connectTime;
	private ?CompoundTag $cachedOfflinePlayerData = null;

	private ?EncryptionContext $cipher = null;

	/** @var string[] */
	private array $sendBuffer = [];
	/** @var string[] */
	private array $chunkCacheBlobs = [];
	private bool $chunkCacheEnabled = false;
	private bool $isFirstPacket = true;

	/**
	 * @var \SplQueue|CompressBatchPromise[]
	 * @phpstan-var \SplQueue<CompressBatchPromise>
	 */
	private \SplQueue $compressedQueue;
	private bool $forceAsyncCompression = true;
	private ?int $protocolId = null;
	private bool $enableCompression = true;

	private PacketSerializerContext $packetSerializerContext;

	private ?InventoryManager $invManager = null;

	/**
	 * @var \Closure[]|ObjectSet
	 * @phpstan-var ObjectSet<\Closure() : void>
	 */
	private ObjectSet $disposeHooks;

	public function __construct(
		private Server $server,
		private NetworkSessionManager $manager,
		private PacketPool $packetPool,
		private PacketSender $sender,
		private PacketBroadcaster $broadcaster,
		private Compressor $compressor,
		private string $ip,
		private int $port
	){
		$this->logger = new \PrefixedLogger($this->server->getLogger(), $this->getLogPrefix());

		$this->compressedQueue = new \SplQueue();

		//TODO: allow this to be injected
		$this->packetSerializerContext = new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary(ProtocolInfo::CURRENT_PROTOCOL));

		$this->disposeHooks = new ObjectSet();

		$this->connectTime = time();
		$this->lastPacketBudgetUpdateTimeNs = hrtime(true);

		$this->setHandler(new SessionStartPacketHandler(
			$this,
			fn() => $this->onSessionStartSuccess()
		));

		$this->manager->add($this);
		$this->logger->info($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_network_session_open()));
	}

	private function getLogPrefix() : string{
		return "NetworkSession: " . $this->getDisplayName();
	}

	public function getLogger() : \Logger{
		return $this->logger;
	}

	private function onSessionStartSuccess() : void{
		$this->logger->debug("Session start handshake completed, awaiting login packet");
		$this->flushSendBuffer(true);
		$this->enableCompression = true;
		$this->setHandler(new LoginPacketHandler(
			$this->server,
			$this,
			function(PlayerInfo $info) : void{
				$this->info = $info;
				$this->logger->info($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_network_session_playerName(TextFormat::AQUA . $info->getUsername() . TextFormat::RESET)));
				$this->logger->setPrefix($this->getLogPrefix());
				$this->manager->markLoginReceived($this);
			},
			\Closure::fromCallable([$this, "setAuthenticationStatus"])
		));
	}

	protected function createPlayer() : void{
		$this->server->createPlayer($this, $this->info, $this->authenticated, $this->cachedOfflinePlayerData)->onCompletion(
			\Closure::fromCallable([$this, 'onPlayerCreated']),
			function() : void{
				//TODO: this should never actually occur... right?
				$this->logger->error("Failed to create player");
				$this->disconnectWithError(KnownTranslationFactory::pocketmine_disconnect_error_internal());
			}
		);
	}

	public function setCacheEnabled(bool $isEnabled) : void{
		//$this->chunkCacheEnabled = $isEnabled;
	}

	public function isCacheEnabled() : bool{
		return $this->chunkCacheEnabled;
	}

	public function removeChunkCache(int $hash) : void{
		unset($this->chunkCacheBlobs[$hash]);
	}

	public function getChunkCache(int $hash) : ?ChunkCacheBlob{
		if(isset($this->chunkCacheBlobs[$hash])){
			return new ChunkCacheBlob($hash, $this->chunkCacheBlobs[$hash]);
		}

		return null;
	}

	private function onPlayerCreated(Player $player) : void{
		if(!$this->isConnected()){
			//the remote player might have disconnected before spawn terrain generation was finished
			return;
		}
		$this->player = $player;
		if(!$this->server->addOnlinePlayer($player)){
			return;
		}

		$this->invManager = new InventoryManager($this->player, $this);

		$effectManager = $this->player->getEffects();
		$effectManager->getEffectAddHooks()->add($effectAddHook = function(EffectInstance $effect, bool $replacesOldEffect) : void{
			$this->onEntityEffectAdded($this->player, $effect, $replacesOldEffect);
		});
		$effectManager->getEffectRemoveHooks()->add($effectRemoveHook = function(EffectInstance $effect) : void{
			$this->onEntityEffectRemoved($this->player, $effect);
		});
		$this->disposeHooks->add(static function() use ($effectManager, $effectAddHook, $effectRemoveHook) : void{
			$effectManager->getEffectAddHooks()->remove($effectAddHook);
			$effectManager->getEffectRemoveHooks()->remove($effectRemoveHook);
		});

		$permissionHooks = $this->player->getPermissionRecalculationCallbacks();
		$permissionHooks->add($permHook = function() : void{
			$this->logger->debug("Syncing available commands and abilities/permissions due to permission recalculation");
			$this->syncAbilities($this->player);
			$this->syncAvailableCommands();
		});
		$this->disposeHooks->add(static function() use ($permissionHooks, $permHook) : void{
			$permissionHooks->remove($permHook);
		});
		$this->beginSpawnSequence();
	}

	public function getPlayer() : ?Player{
		return $this->player;
	}

	public function getPlayerInfo() : ?PlayerInfo{
		return $this->info;
	}

	public function isConnected() : bool{
		return $this->connected && !$this->disconnectGuard;
	}

	public function getIp() : string{
		return $this->ip;
	}

	public function getPort() : int{
		return $this->port;
	}

	public function getDisplayName() : string{
		return $this->info !== null ? $this->info->getUsername() : $this->ip . " " . $this->port;
	}

	/**
	 * Returns the last recorded ping measurement for this session, in milliseconds, or null if a ping measurement has not yet been recorded.
	 */
	public function getPing() : ?int{
		return $this->ping;
	}

	/**
	 * @internal Called by the network interface to update last recorded ping measurements.
	 */
	public function updatePing(int $ping) : void{
		$this->ping = $ping;
	}

	public function getHandler() : ?PacketHandler{
		return $this->handler;
	}

	public function setHandler(?PacketHandler $handler) : void{
		if($this->connected){ //TODO: this is fine since we can't handle anything from a disconnected session, but it might produce surprises in some cases
			$this->handler = $handler;
			if($this->handler !== null){
				$this->handler->setUp();
			}
		}
	}

	public function setProtocolId(int $protocolId) : void{
		$this->protocolId = $protocolId;

		$this->broadcaster = RakLibInterface::getBroadcaster($this->server, $protocolId);
		$this->packetSerializerContext = new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary(GlobalItemTypeDictionary::getDictionaryProtocol($protocolId)));
	}

	public function getProtocolId() : int{
		return $this->protocolId ?? ProtocolInfo::CURRENT_PROTOCOL;
	}

	/**
	 * @throws PacketHandlingException
	 */
	public function handleEncoded(string $payload) : void{
		if(!$this->connected){
			return;
		}

		Timings::$playerNetworkReceive->startTiming();
		try{
			if($this->incomingPacketBatchBudget <= 0){
				$this->updatePacketBudget();
				if($this->incomingPacketBatchBudget <= 0){
					throw new PacketHandlingException("Receiving packets too fast");
				}
			}
			$this->incomingPacketBatchBudget--;

			if($this->cipher !== null){
				Timings::$playerNetworkReceiveDecrypt->startTiming();
				try{
					$payload = $this->cipher->decrypt($payload);
				}catch(DecryptionException $e){
					$this->logger->debug("Encrypted packet: " . base64_encode($payload));
					throw PacketHandlingException::wrap($e, "Packet decryption error");
				}finally{
					Timings::$playerNetworkReceiveDecrypt->stopTiming();
				}
			}

			if($this->enableCompression){
				Timings::$playerNetworkReceiveDecompress->startTiming();
				try{
					$decompressed = $this->compressor->decompress($payload);
				}catch(DecompressionException $e){
					if($this->isFirstPacket){
					$this->logger->debug("Failed to decompress packet, assuming client is using the new compression method");

					$this->enableCompression = false;
					$this->setHandler(new SessionStartPacketHandler(
						$this,
						fn() => $this->onSessionStartSuccess()
					));

					$decompressed = $payload;
				}else{
					$this->logger->debug("Failed to decompress packet: " . base64_encode($payload));
					throw PacketHandlingException::wrap($e, "Compressed packet batch decode error");
				}
				}finally{
					Timings::$playerNetworkReceiveDecompress->stopTiming();
				}
			}else{
				$decompressed = $payload;
			}

			try{
				$stream = new BinaryStream($decompressed);
				$count = 0;
				foreach(PacketBatch::decodeRaw($stream) as $buffer){
					if(++$count > 1300){
						throw new PacketHandlingException("Too many packets in batch");
					}
					$packet = $this->packetPool->getPacket($buffer);
					if($packet === null){
						$this->logger->debug("Unknown packet: " . base64_encode($buffer));
						throw new PacketHandlingException("Unknown packet received");
					}
					try{
						$this->handleDataPacket($packet, $this->getProtocolId(), $buffer);
					}catch(PacketHandlingException $e){
						$this->logger->debug($packet->getName() . ": " . base64_encode($buffer));
						throw PacketHandlingException::wrap($e, "Error processing " . $packet->getName());
					}
				}
			}catch(PacketDecodeException $e){
				$this->logger->logException($e);
				throw PacketHandlingException::wrap($e, "Packet batch decode error");
			}finally{
				$this->isFirstPacket = false;
			}
		}finally{
			Timings::$playerNetworkReceive->stopTiming();
		}
	}

	/**
	 * @throws PacketHandlingException
	 */
	public function handleDataPacket(Packet $packet, int $protocolId, string $buffer) : void{
		if(!($packet instanceof ServerboundPacket)){
			throw new PacketHandlingException("Unexpected non-serverbound packet");
		}

		$timings = Timings::getDecodeDataPacketTimings($packet);
		$timings->startTiming();
		try{
			$stream = PacketSerializer::decoder($buffer, 0, $this->packetSerializerContext, $protocolId);
			try{
				$packet->decode($stream);
			}catch(PacketDecodeException $e){
				throw PacketHandlingException::wrap($e);
			}
			if(!$stream->feof()){
				$remains = substr($stream->getBuffer(), $stream->getOffset());
				$this->logger->debug("Still " . strlen($remains) . " bytes unread in " . $packet->getName() . ": " . bin2hex($remains));
			}
		}finally{
			$timings->stopTiming();
		}

		$timings = Timings::getHandleDataPacketTimings($packet);
		$timings->startTiming();
		try{
			//TODO: I'm not sure DataPacketReceiveEvent should be included in the handler timings, but it needs to be
			//included for now to ensure the receivePacket timings are counted the way they were before
			$ev = new DataPacketReceiveEvent($this, $packet);
			$ev->call();
			if(!$ev->isCancelled() && ($this->handler === null || !$packet->handle($this->handler))){
				$this->logger->debug("Unhandled " . $packet->getName() . ": " . base64_encode($stream->getBuffer()));
			}
		}finally{
			$timings->stopTiming();
		}
	}

	public function sendDataPacket(ClientboundPacket $packet, bool $immediate = false) : bool{
		if(!$this->connected){
			return false;
		}
		//Basic safety restriction. TODO: improve this
		if(!$this->loggedIn && !$packet->canBeSentBeforeLogin()){
			throw new \InvalidArgumentException("Attempted to send " . get_class($packet) . " to " . $this->getDisplayName() . " too early");
		}

		$timings = Timings::getSendDataPacketTimings($packet);
		$timings->startTiming();
		try{
			$ev = new DataPacketSendEvent([$this], [$packet]);
			$ev->call();
			if($ev->isCancelled()){
				return false;
			}
			$packets = $ev->getPackets();

			foreach($packets as $evPacket){
				$this->addToSendBuffer(self::encodePacketTimed(PacketSerializer::encoder($this->packetSerializerContext), $evPacket));
			}
			if($immediate){
				$this->flushSendBuffer(true);
			}

			return true;
		}finally{
			$timings->stopTiming();
		}
	}

	/**
	 * @internal
	 */
	public static function encodePacketTimed(PacketSerializer $serializer, ClientboundPacket $packet) : string{
		$timings = Timings::getEncodeDataPacketTimings($packet);
		$timings->startTiming();
		try{
			$packet->encode($serializer);
			return $serializer->getBuffer();
		}finally{
			$timings->stopTiming();
		}
	}

	/**
	 * @internal
	 */
	public function addToSendBuffer(string $buffer) : void{
		$this->sendBuffer[] = $buffer;
	}

	private function flushSendBuffer(bool $immediate = false) : void{
		if(count($this->sendBuffer) > 0){
			Timings::$playerNetworkSend->startTiming();
			try{
				$syncMode = null; //automatic
				if($immediate){
					$syncMode = true;
				}elseif($this->forceAsyncCompression){
					$syncMode = false;
				}

				$stream = new BinaryStream();
				PacketBatch::encodeRaw($stream, $this->sendBuffer);

				if($this->enableCompression){
					$promise = $this->server->prepareBatch(new PacketBatch($stream->getBuffer()), $this->compressor, $syncMode);
				}else{
					$promise = new CompressBatchPromise();
					$promise->resolve($stream->getBuffer());
				}
				$this->sendBuffer = [];
				$this->queueCompressedNoBufferFlush($promise, $immediate);
			}finally{
				Timings::$playerNetworkSend->stopTiming();
			}
		}
	}

	public function getPacketSerializerContext() : PacketSerializerContext{ return $this->packetSerializerContext; }

	public function getBroadcaster() : PacketBroadcaster{ return $this->broadcaster; }

	public function getCompressor() : Compressor{
		return $this->compressor;
	}

	public function queueCompressed(CompressBatchPromise $payload, bool $immediate = false) : void{
		Timings::$playerNetworkSend->startTiming();
		try{
			$this->flushSendBuffer($immediate); //Maintain ordering if possible
			$this->queueCompressedNoBufferFlush($payload, $immediate);
		}finally{
			Timings::$playerNetworkSend->stopTiming();
		}
	}

	private function queueCompressedNoBufferFlush(CompressBatchPromise $payload, bool $immediate = false) : void{
		Timings::$playerNetworkSend->startTiming();
		try{
			if($immediate){
				//Skips all queues
				$this->sendEncoded($payload->getResult(), true);
			}else{
				$this->compressedQueue->enqueue($payload);
				$payload->onResolve(function(CompressBatchPromise $payload) : void{
					if($this->connected && $this->compressedQueue->bottom() === $payload){
						$this->compressedQueue->dequeue(); //result unused
						$this->sendEncoded($payload->getResult());

						while(!$this->compressedQueue->isEmpty()){
							/** @var CompressBatchPromise $current */
							$current = $this->compressedQueue->bottom();
							if($current->hasResult()){
								$this->compressedQueue->dequeue();

								$this->sendEncoded($current->getResult());
							}else{
								//can't send any more queued until this one is ready
								break;
							}
						}
					}
				});
			}
		}finally{
			Timings::$playerNetworkSend->stopTiming();
		}
	}

	private function sendEncoded(string $payload, bool $immediate = false) : void{
		if($this->cipher !== null){
			Timings::$playerNetworkSendEncrypt->startTiming();
			$payload = $this->cipher->encrypt($payload);
			Timings::$playerNetworkSendEncrypt->stopTiming();
		}
		$this->sender->send($payload, $immediate);
	}

	/**
	 * @phpstan-param \Closure() : void $func
	 */
	private function tryDisconnect(\Closure $func, Translatable|string $reason) : void{
		if($this->connected && !$this->disconnectGuard){
			$this->disconnectGuard = true;
			$func();

			$event = new SessionDisconnectEvent($this);
			$event->call();

			$this->disconnectGuard = false;
			$this->flushSendBuffer(true);
			$this->sender->close("");
			foreach($this->disposeHooks as $callback){
				$callback();
			}
			$this->disposeHooks->clear();
			$this->setHandler(null);
			$this->connected = false;

			$this->logger->info($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_network_session_close($reason)));
		}
	}

	/**
	 * Performs actions after the session has been disconnected. By this point, nothing should be interacting with the
	 * session, so it's safe to destroy any cycles and perform destructive cleanup.
	 */
	private function dispose() : void{
		$this->invManager = null;
	}

	private function sendDisconnectPacket(Translatable|string $message) : void{
		if($message instanceof Translatable){
			$translated = $this->server->getLanguage()->translate($message);
		}else{
			$translated = $message;
		}
		$this->sendDataPacket(DisconnectPacket::create($translated));
	}

	/**
	 * Disconnects the session, destroying the associated player (if it exists).
	 *
	 * @param Translatable|string      $reason                  Shown in the server log - this should be a short one-line message
	 * @param Translatable|string|null $disconnectScreenMessage Shown on the player's disconnection screen (null will use the reason)
	 */
	public function disconnect(Translatable|string $reason, Translatable|string|null $disconnectScreenMessage = null, bool $notify = true) : void{
		$this->tryDisconnect(function() use ($reason, $disconnectScreenMessage, $notify) : void{
			if($notify){
				$this->sendDisconnectPacket($disconnectScreenMessage ?? $reason);
			}
			if($this->player !== null){
				$this->player->onPostDisconnect($reason, null);
			}
		}, $reason);
	}

	public function disconnectWithError(Translatable|string $reason) : void{
		$this->disconnect(KnownTranslationFactory::pocketmine_disconnect_error($reason, bin2hex(random_bytes(6))));
	}

	public function disconnectIncompatibleProtocol(int $protocolVersion) : void{
		$this->tryDisconnect(
			function() use ($protocolVersion) : void{
				$this->sendDataPacket(PlayStatusPacket::create($protocolVersion < ProtocolInfo::CURRENT_PROTOCOL ? PlayStatusPacket::LOGIN_FAILED_CLIENT : PlayStatusPacket::LOGIN_FAILED_SERVER), true);
			},
			KnownTranslationFactory::pocketmine_disconnect_incompatibleProtocol((string) $protocolVersion)
		);
	}

	/**
	 * Instructs the remote client to connect to a different server.
	 */
	public function transfer(string $ip, int $port, Translatable|string|null $reason = null) : void{
		$reason ??= KnownTranslationFactory::pocketmine_disconnect_transfer();
		$this->flushChunkCache();
		$this->tryDisconnect(function() use ($ip, $port, $reason) : void{
			$this->sendDataPacket(TransferPacket::create($ip, $port), true);
			if($this->player !== null){
				$this->player->onPostDisconnect($reason, null);
			}
		}, $reason);
	}

	/**
	 * Called by the Player when it is closed (for example due to getting kicked).
	 */
	public function onPlayerDestroyed(Translatable|string $reason, Translatable|string $disconnectScreenMessage) : void{
		$this->tryDisconnect(function() use ($disconnectScreenMessage) : void{
			$this->sendDisconnectPacket($disconnectScreenMessage);
		}, $reason);
	}

	/**
	 * Called by the network interface to close the session when the client disconnects without server input, for
	 * example in a timeout condition or voluntary client disconnect.
	 */
	public function onClientDisconnect(Translatable|string $reason) : void{
		$this->tryDisconnect(function() use ($reason) : void{
			if($this->player !== null){
				$this->player->onPostDisconnect($reason, null);
			}
		}, $reason);
	}

	private function setAuthenticationStatus(bool $authenticated, bool $authRequired, Translatable|string|null $error, ?string $clientPubKey) : void{
		if(!$this->connected){
			return;
		}
		if($error === null){
			if($authenticated && !($this->info instanceof XboxLivePlayerInfo)){
				$error = "Expected XUID but none found";
			}elseif($clientPubKey === null){
				$error = "Missing client public key"; //failsafe
			}
		}

		if($error !== null){
			$this->disconnectWithError(KnownTranslationFactory::pocketmine_disconnect_invalidSession($error));

			return;
		}

		$this->authenticated = $authenticated;

		if(!$this->authenticated){
			if($authRequired){
				$this->disconnect("Not authenticated", KnownTranslationFactory::disconnectionScreen_notAuthenticated());
				return;
			}
			if($this->info instanceof XboxLivePlayerInfo){
				$this->logger->warning("Discarding unexpected XUID for non-authenticated player");
				$this->info = $this->info->withoutXboxData();
			}
		}
		$this->logger->debug("Xbox Live authenticated: " . ($this->authenticated ? "YES" : "NO"));

		$checkXUID = $this->server->getConfigGroup()->getPropertyBool("player.verify-xuid", true);
		$myXUID = $this->info instanceof XboxLivePlayerInfo ? $this->info->getXuid() : "";
		$kickForXUIDMismatch = function(string $xuid) use ($checkXUID, $myXUID) : bool{
			if($checkXUID && $myXUID !== $xuid){
				$this->logger->debug("XUID mismatch: expected '$xuid', but got '$myXUID'");
				//TODO: Longer term, we should be identifying playerdata using something more reliable, like XUID or UUID.
				//However, that would be a very disruptive change, so this will serve as a stopgap for now.
				//Side note: this will also prevent offline players hijacking XBL playerdata on online servers, since their
				//XUID will always be empty.
				$this->disconnect("XUID does not match (possible impersonation attempt)");
				return true;
			}
			return false;
		};

		foreach($this->manager->getSessions() as $existingSession){
			if($existingSession === $this){
				continue;
			}
			$info = $existingSession->getPlayerInfo();
			if($info !== null && (strcasecmp($info->getUsername(), $this->info->getUsername()) === 0 || $info->getUuid()->equals($this->info->getUuid()))){
				if($kickForXUIDMismatch($info instanceof XboxLivePlayerInfo ? $info->getXuid() : "")){
					return;
				}
				$ev = new PlayerDuplicateLoginEvent($this, $existingSession, KnownTranslationFactory::disconnectionScreen_loggedinOtherLocation(), null);
				$ev->call();
				if($ev->isCancelled()){
					$this->disconnect($ev->getDisconnectReason(), $ev->getDisconnectScreenMessage());
					return;
				}

				$existingSession->disconnect($ev->getDisconnectReason(), $ev->getDisconnectScreenMessage());
			}
		}

		//TODO: make player data loading async
		//TODO: we shouldn't be loading player data here at all, but right now we don't have any choice :(
		$this->cachedOfflinePlayerData = $this->server->getOfflinePlayerData($this->info->getUsername());
		if($checkXUID){
			$recordedXUID = $this->cachedOfflinePlayerData !== null ? $this->cachedOfflinePlayerData->getTag(Player::TAG_LAST_KNOWN_XUID) : null;
			if(!($recordedXUID instanceof StringTag)){
				$this->logger->debug("No previous XUID recorded, no choice but to trust this player");
			}elseif(!$kickForXUIDMismatch($recordedXUID->getValue())){
				$this->logger->debug("XUID match");
			}
		}

		if(EncryptionContext::$ENABLED){
			$this->server->getAsyncPool()->submitTask(new PrepareEncryptionTask($clientPubKey, function(string $encryptionKey, string $handshakeJwt) : void{
				if(!$this->connected){
					return;
				}
				$this->sendDataPacket(ServerToClientHandshakePacket::create($handshakeJwt), true); //make sure this gets sent before encryption is enabled

				$this->cipher = EncryptionContext::fakeGCM($encryptionKey);

				$this->setHandler(new HandshakePacketHandler(function() : void{
					$this->onServerLoginSuccess();
				}));
				$this->logger->debug("Enabled encryption");
			}));
		}else{
			$this->onServerLoginSuccess();
		}
	}

	private function onServerLoginSuccess() : void{
		$this->loggedIn = true;

		$this->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::LOGIN_SUCCESS));

		$this->logger->debug("Initiating resource packs phase");
		$this->setHandler(new ResourcePacksPacketHandler($this, $this->server->getResourcePackManager(), function() : void{
			$this->createPlayer();
		}));
	}

	private function beginSpawnSequence() : void{
		$this->setHandler(new PreSpawnPacketHandler($this->server, $this->player, $this, $this->invManager));
		$this->player->setImmobile(); //TODO: HACK: fix client-side falling pre-spawn

		$this->logger->debug("Waiting for chunk radius request");
	}

	public function notifyTerrainReady() : void{
		$this->logger->debug("Sending spawn notification, waiting for spawn response");
		$this->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::PLAYER_SPAWN));
		$this->setHandler(new SpawnResponsePacketHandler(function() : void{
			$this->onClientSpawnResponse();
		}, $this));
	}

	private function onClientSpawnResponse() : void{
		$this->logger->debug("Received spawn response, entering in-game phase");
		$this->player->setImmobile(false); //TODO: HACK: we set this during the spawn sequence to prevent the client sending junk movements
		$this->player->doFirstSpawn();
		$this->forceAsyncCompression = false;
		$this->setHandler(new InGamePacketHandler($this->player, $this, $this->invManager));
	}

	public function onServerDeath(Translatable|string $deathMessage) : void{
		if($this->handler instanceof InGamePacketHandler){ //TODO: this is a bad fix for pre-spawn death, this shouldn't be reachable at all at this stage :(
			$this->setHandler(new DeathPacketHandler($this->player, $this, $this->invManager ?? throw new AssumptionFailedError(), $deathMessage));
		}
	}

	public function onServerRespawn() : void{
		$this->syncAttributes($this->player, $this->player->getAttributeMap()->getAll());
		$this->player->sendData(null);

		$this->syncAbilities($this->player);
		$this->invManager->syncAll();
		$this->setHandler(new InGamePacketHandler($this->player, $this, $this->invManager));
	}

	public function syncMovement(Vector3 $pos, ?float $yaw = null, ?float $pitch = null, int $mode = MovePlayerPacket::MODE_NORMAL) : void{
		if($this->player !== null){
			$location = $this->player->getLocation();
			$yaw = $yaw ?? $location->getYaw();
			$pitch = $pitch ?? $location->getPitch();

			$this->sendDataPacket(MovePlayerPacket::simple(
				$this->player->getId(),
				$this->player->getOffsetPosition($pos),
				$pitch,
				$yaw,
				$yaw, //TODO: head yaw
				$mode,
				$this->player->onGround,
				0, //TODO: riding entity ID
				0 //TODO: tick
			));

			if($this->handler instanceof InGamePacketHandler){
				$this->handler->forceMoveSync = true;
			}
		}
	}

	public function syncViewAreaRadius(int $distance) : void{
		$this->sendDataPacket(ChunkRadiusUpdatedPacket::create($distance));
	}

	public function syncViewAreaCenterPoint(Vector3 $newPos, int $viewDistance) : void{
		$this->sendDataPacket(NetworkChunkPublisherUpdatePacket::create(BlockPosition::fromVector3($newPos), $viewDistance * 16, [])); //blocks, not chunks >.>
	}

	public function syncPlayerSpawnPoint(Position $newSpawn) : void{
		$newSpawnBlockPosition = BlockPosition::fromVector3($newSpawn);
		//TODO: respawn causing block position (bed, respawn anchor)
		$this->sendDataPacket(SetSpawnPositionPacket::playerSpawn($newSpawnBlockPosition, DimensionIds::OVERWORLD, $newSpawnBlockPosition));
	}

	public function syncWorldSpawnPoint(Position $newSpawn) : void{
		$this->sendDataPacket(SetSpawnPositionPacket::worldSpawn(BlockPosition::fromVector3($newSpawn), DimensionIds::OVERWORLD));
	}

	public function syncGameMode(GameMode $mode, bool $isRollback = false) : void{
		$this->sendDataPacket(SetPlayerGameTypePacket::create(TypeConverter::getInstance()->coreGameModeToProtocol($mode)));
		if($this->player !== null){
			$this->syncAbilities($this->player);
			$this->syncAdventureSettings(); //TODO: we might be able to do this with the abilities packet alone
		}
		if(!$isRollback && $this->invManager !== null){
			$this->invManager->syncCreative();
		}
	}

	public function syncAbilities(Player $for) : void{
		$isOp = $for->hasPermission(DefaultPermissions::ROOT_OPERATOR);

		if($this->getProtocolId() >= ProtocolInfo::PROTOCOL_1_19_10){
			//ALL of these need to be set for the base layer, otherwise the client will cry
			$boolAbilities = [
				AbilitiesLayer::ABILITY_ALLOW_FLIGHT => $for->getAllowFlight(),
				AbilitiesLayer::ABILITY_FLYING => $for->isFlying(),
				AbilitiesLayer::ABILITY_NO_CLIP => !$for->hasBlockCollision(),
				AbilitiesLayer::ABILITY_OPERATOR => $isOp,
				AbilitiesLayer::ABILITY_TELEPORT => $for->hasPermission(DefaultPermissionNames::COMMAND_TELEPORT_SELF),
				AbilitiesLayer::ABILITY_INVULNERABLE => $for->isCreative(),
				AbilitiesLayer::ABILITY_MUTED => false,
				AbilitiesLayer::ABILITY_WORLD_BUILDER => false,
				AbilitiesLayer::ABILITY_INFINITE_RESOURCES => !$for->hasFiniteResources(),
				AbilitiesLayer::ABILITY_LIGHTNING => false,
				AbilitiesLayer::ABILITY_BUILD => !$for->isSpectator(),
				AbilitiesLayer::ABILITY_MINE => !$for->isSpectator(),
				AbilitiesLayer::ABILITY_DOORS_AND_SWITCHES => !$for->isSpectator(),
				AbilitiesLayer::ABILITY_OPEN_CONTAINERS => !$for->isSpectator(),
				AbilitiesLayer::ABILITY_ATTACK_PLAYERS => !$for->isSpectator(),
				AbilitiesLayer::ABILITY_ATTACK_MOBS => !$for->isSpectator(),
			];

			$pk = UpdateAbilitiesPacket::create(new AbilitiesData(
				$isOp ? CommandPermissions::OPERATOR : CommandPermissions::NORMAL,
				$isOp ? PlayerPermissions::OPERATOR : PlayerPermissions::MEMBER,
				$for->getId(),
				[
					//TODO: dynamic flying speed! FINALLY!!!!!!!!!!!!!!!!!
					new AbilitiesLayer(AbilitiesLayer::LAYER_BASE, $boolAbilities, 0.05, 0.1),
				]
			));
		}else{
			$pk = AdventureSettingsPacket::create(
				0,
				$isOp ? CommandPermissions::OPERATOR : CommandPermissions::NORMAL,
				0,
				$isOp ? PlayerPermissions::OPERATOR : PlayerPermissions::MEMBER,
				0,
				$for->getId()
			);

			$pk->setFlag(AdventureSettingsPacket::WORLD_IMMUTABLE, $for->isSpectator());
			$pk->setFlag(AdventureSettingsPacket::NO_PVP, $for->isSpectator());
			$pk->setFlag(AdventureSettingsPacket::AUTO_JUMP, $for->hasAutoJump());
			$pk->setFlag(AdventureSettingsPacket::ALLOW_FLIGHT, $for->getAllowFlight());
			$pk->setFlag(AdventureSettingsPacket::NO_CLIP, !$for->hasBlockCollision());
			$pk->setFlag(AdventureSettingsPacket::FLYING, $for->isFlying());
		}

		$this->sendDataPacket($pk);
	}

	public function syncAdventureSettings() : void{
		if($this->player === null){
			throw new \LogicException("Cannot sync adventure settings for a player that is not yet created");
		}

		if($this->getProtocolId() >= ProtocolInfo::PROTOCOL_1_19_10){
			//everything except auto jump is handled via UpdateAbilitiesPacket
			$this->sendDataPacket(UpdateAdventureSettingsPacket::create(
				noAttackingMobs: false,
				noAttackingPlayers: false,
				worldImmutable: false,
				showNameTags: true,
				autoJump: $this->player->hasAutoJump()
			));
		}
	}

	/**
	 * @param Attribute[] $attributes
	 */
	public function syncAttributes(Living $entity, array $attributes) : void{
		if(count($attributes) > 0){
			$this->sendDataPacket(UpdateAttributesPacket::create($entity->getId(), array_map(function(Attribute $attr) : NetworkAttribute{
				return new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue(), $attr->getDefaultValue(), []);
			}, $attributes), 0));
		}
	}

	/**
	 * @param MetadataProperty[] $properties
	 * @phpstan-param array<int, MetadataProperty> $properties
	 */
	public function syncActorData(Entity $entity, array $properties) : void{
		//TODO: HACK! as of 1.18.10, the client responds differently to the same data ordered in different orders - for
		//example, sending HEIGHT in the list before FLAGS when unsetting the SWIMMING flag results in a hitbox glitch
		ksort($properties, SORT_NUMERIC);
		$this->sendDataPacket(SetActorDataPacket::create($entity->getId(), $properties, new PropertySyncData([], []), 0));
	}

	public function onEntityEffectAdded(Living $entity, EffectInstance $effect, bool $replacesOldEffect) : void{
		//TODO: we may need yet another effect <=> ID map in the future depending on protocol changes
		$this->sendDataPacket(MobEffectPacket::add($entity->getId(), $replacesOldEffect, EffectIdMap::getInstance()->toId($effect->getType()), $effect->getAmplifier(), $effect->isVisible(), $effect->getDuration()));
	}

	public function onEntityEffectRemoved(Living $entity, EffectInstance $effect) : void{
		$this->sendDataPacket(MobEffectPacket::remove($entity->getId(), EffectIdMap::getInstance()->toId($effect->getType())));
	}

	public function onEntityRemoved(Entity $entity) : void{
		$this->sendDataPacket(RemoveActorPacket::create($entity->getId()));
	}

	public function syncAvailableCommands() : void{
		$commandData = [];
		foreach($this->server->getCommandMap()->getCommands() as $name => $command){
			if(isset($commandData[$command->getLabel()]) || $command->getLabel() === "help" || !$command->testPermissionSilent($this->player)){
				continue;
			}

			$lname = strtolower($command->getLabel());
			$aliases = $command->getAliases();
			$aliasObj = null;
			if(count($aliases) > 0){
				if(!in_array($lname, $aliases, true)){
					//work around a client bug which makes the original name not show when aliases are used
					$aliases[] = $lname;
				}
				$aliasObj = new CommandEnum(ucfirst($command->getLabel()) . "Aliases", array_values($aliases));
			}

			$description = $command->getDescription();
			$data = new CommandData(
				$lname, //TODO: commands containing uppercase letters in the name crash 1.9.0 client
				$description instanceof Translatable ? $this->player->getLanguage()->translate($description) : $description,
				0,
				0,
				$aliasObj,
				[
					[CommandParameter::standard("args", AvailableCommandsPacket::convertArg($this->getProtocolId(), AvailableCommandsPacket::ARG_TYPE_RAWTEXT), 0, true)]
				]
			);

			$commandData[$command->getLabel()] = $data;
		}

		$this->sendDataPacket(AvailableCommandsPacket::create($commandData, [], [], []));
	}

	/**
	 * @return string[][]
	 * @phpstan-return array{string, string[]}
	 */
	public function prepareClientTranslatableMessage(Translatable $message) : array{
		//we can't send nested translations to the client, so make sure they are always pre-translated by the server
		$language = $this->player->getLanguage();
		$parameters = array_map(fn(string|Translatable $p) => $p instanceof Translatable ? $language->translate($p) : $p, $message->getParameters());
		return [$language->translateString($message->getText(), $parameters, "pocketmine."), $parameters];
	}

	public function onChatMessage(Translatable|string $message) : void{
		if($message instanceof Translatable){
			if(!$this->server->isLanguageForced()){
				$this->sendDataPacket(TextPacket::translation(...$this->prepareClientTranslatableMessage($message)));
			}else{
				$this->sendDataPacket(TextPacket::raw($this->player->getLanguage()->translate($message)));
			}
		}else{
			$this->sendDataPacket(TextPacket::raw($message));
		}
	}

	public function onJukeboxPopup(Translatable|string $message) : void{
		$parameters = [];
		if($message instanceof Translatable){
			if(!$this->server->isLanguageForced()){
				[$message, $parameters] = $this->prepareClientTranslatableMessage($message);
			}else{
				$message = $this->player->getLanguage()->translate($message);
			}
		}
		$this->sendDataPacket(TextPacket::jukeboxPopup($message, $parameters));
	}

	public function onPopup(string $message) : void{
		$this->sendDataPacket(TextPacket::popup($message));
	}

	public function onTip(string $message) : void{
		$this->sendDataPacket(TextPacket::tip($message));
	}

	public function onFormSent(int $id, Form $form) : bool{
		return $this->sendDataPacket(ModalFormRequestPacket::create($id, json_encode($form, JSON_THROW_ON_ERROR)));
	}

	/**
	 * Instructs the networksession to start using the chunk at the given coordinates. This may occur asynchronously.
	 * @param \Closure $onCompletion To be called when chunk sending has completed.
	 * @phpstan-param \Closure() : void $onCompletion
	 */
	public function startUsingChunk(int $chunkX, int $chunkZ, \Closure $onCompletion) : void{
		Utils::validateCallableSignature(function() : void{}, $onCompletion);

		$world = $this->player->getLocation()->getWorld();
		ChunkCache::getInstance($world, $this->compressor)->request($chunkX, $chunkZ, $this->getProtocolId())->onResolve(

			//this callback may be called synchronously or asynchronously, depending on whether the promise is resolved yet
			function(CachedChunkPromise $promise) use ($world, $onCompletion, $chunkX, $chunkZ) : void{

				if(!$this->isConnected()){
					return;
				}
				$currentWorld = $this->player->getLocation()->getWorld();
				if($world !== $currentWorld || ($status = $this->player->getUsedChunkStatus($chunkX, $chunkZ)) === null){
					$this->logger->debug("Tried to send no-longer-active chunk $chunkX $chunkZ in world " . $world->getFolderName());
					return;
				}
				if(!$status->equals(UsedChunkStatus::REQUESTED_SENDING())){
					//TODO: make this an error
					//this could be triggered due to the shitty way that chunk resends are handled
					//right now - not because of the spammy re-requesting, but because the chunk status reverts
					//to NEEDED if they want to be resent.
					return;
				}

				$compressBatchPromise = new CompressBatchPromise();
				$result = $promise->getResult();

				if($this->isCacheEnabled()){
					$compressBatchPromise->resolve($result->getCacheablePacket());

					$this->chunkCacheBlobs = array_replace($this->chunkCacheBlobs, $result->getHashMap());
					if(count($this->chunkCacheBlobs) > 4096) {
						$this->disconnect("Too many pending blobs");
						return;
					}
				}else{
					$compressBatchPromise->resolve($result->getPacket());
				}

				$world->timings->syncChunkSend->startTiming();
				try{
					$this->queueCompressed($compressBatchPromise);
					$onCompletion();

					if($this->getProtocolId() === ProtocolInfo::PROTOCOL_1_19_10){
						//TODO: HACK! we send the full tile data here, due to a bug in 1.19.10 which causes items in tiles
						//(item frames, lecterns) to not load properly when they are sent in a chunk via the classic chunk
						//sending mechanism. We workaround this bug by sending only bare essential data in LevelChunkPacket
						//(enough to create the tiles, since BlockActorDataPacket can't create tiles by itself) and then
						//send the actual tile properties here.
						//TODO: maybe we can stuff these packets inside the cached batch alongside LevelChunkPacket?
						$chunk = $currentWorld->getChunk($chunkX, $chunkZ);
						if($chunk !== null){
							foreach($chunk->getTiles() as $tile){
								if(!($tile instanceof Spawnable)){
									continue;
								}
								$this->sendDataPacket(BlockActorDataPacket::create(BlockPosition::fromVector3($tile->getPosition()), $tile->getSerializedSpawnCompound()));
							}
						}
					}
				}finally{
					$world->timings->syncChunkSend->stopTiming();
				}
			}
		);
	}

	public function stopUsingChunk(int $chunkX, int $chunkZ) : void{

	}

	public function onEnterWorld() : void{
		if($this->player !== null){
			$world = $this->player->getWorld();
			$this->syncWorldTime($world->getTime());
			$this->syncWorldDifficulty($world->getDifficulty());
			$this->syncWorldSpawnPoint($world->getSpawnLocation());
			//TODO: weather needs to be synced here (when implemented)
		}
	}

	public function syncWorldTime(int $worldTime) : void{
		$this->sendDataPacket(SetTimePacket::create($worldTime));
	}

	public function syncWorldDifficulty(int $worldDifficulty) : void{
		$this->sendDataPacket(SetDifficultyPacket::create($worldDifficulty));
	}

	public function getInvManager() : ?InventoryManager{
		return $this->invManager;
	}

	/**
	 * TODO: expand this to more than just humans
	 */
	public function onMobMainHandItemChange(Human $mob) : void{
		//TODO: we could send zero for slot here because remote players don't need to know which slot was selected
		$inv = $mob->getInventory();
		$this->sendDataPacket(MobEquipmentPacket::create($mob->getId(), ItemStackWrapper::legacy(TypeConverter::getInstance()->coreItemStackToNet($inv->getItemInHand(), $this->getProtocolId())), $inv->getHeldItemIndex(), $inv->getHeldItemIndex(), ContainerIds::INVENTORY));
	}

	public function onMobOffHandItemChange(Human $mob) : void{
		$inv = $mob->getOffHandInventory();
		$this->sendDataPacket(MobEquipmentPacket::create($mob->getId(), ItemStackWrapper::legacy(TypeConverter::getInstance()->coreItemStackToNet($inv->getItem(0), $this->getProtocolId())), 0, 0, ContainerIds::OFFHAND));
	}

	public function onMobArmorChange(Living $mob) : void{
		$inv = $mob->getArmorInventory();
		$converter = TypeConverter::getInstance();
		$protocolId = $this->getProtocolId();
		$this->sendDataPacket(MobArmorEquipmentPacket::create(
			$mob->getId(),
			ItemStackWrapper::legacy($converter->coreItemStackToNet($inv->getHelmet(), $protocolId)),
			ItemStackWrapper::legacy($converter->coreItemStackToNet($inv->getChestplate(), $protocolId)),
			ItemStackWrapper::legacy($converter->coreItemStackToNet($inv->getLeggings(), $protocolId)),
			ItemStackWrapper::legacy($converter->coreItemStackToNet($inv->getBoots(), $protocolId))
		));
	}

	public function onPlayerPickUpItem(Player $collector, Entity $pickedUp) : void{
		$this->sendDataPacket(TakeItemActorPacket::create($collector->getId(), $pickedUp->getId()));
	}

	/**
	 * @param Player[] $players
	 */
	public function syncPlayerList(array $players) : void{
		$this->sendDataPacket(PlayerListPacket::create(new PlayerListAdditionEntries(array_map(function(Player $player) : PlayerListAdditionEntry{
			return new PlayerListAdditionEntry($player->getUniqueId(), $player->getId(), $player->getDisplayName(), SkinAdapterSingleton::get()->toSkinData($player->getSkin()), $player->getXuid());
		}, $players))));
	}

	public function onPlayerAdded(Player $p) : void{
		$this->sendDataPacket(PlayerListPacket::create(new PlayerListAdditionEntries([new PlayerListAdditionEntry($p->getUniqueId(), $p->getId(), $p->getDisplayName(), SkinAdapterSingleton::get()->toSkinData($p->getSkin()), $p->getXuid())])));
	}

	public function onPlayerRemoved(Player $p) : void{
		if($p !== $this->player){
			$this->sendDataPacket(PlayerListPacket::create(new PlayerListRemovalEntries([$p->getUniqueId()])));
		}
	}

	public function onTitle(string $title) : void{
		$this->sendDataPacket(SetTitlePacket::title($title));
	}

	public function onSubTitle(string $subtitle) : void{
		$this->sendDataPacket(SetTitlePacket::subtitle($subtitle));
	}

	public function onActionBar(string $actionBar) : void{
		$this->sendDataPacket(SetTitlePacket::actionBarMessage($actionBar));
	}

	public function onClearTitle() : void{
		$this->sendDataPacket(SetTitlePacket::clearTitle());
	}

	public function onResetTitleOptions() : void{
		$this->sendDataPacket(SetTitlePacket::resetTitleOptions());
	}

	public function onTitleDuration(int $fadeIn, int $stay, int $fadeOut) : void{
		$this->sendDataPacket(SetTitlePacket::setAnimationTimes($fadeIn, $stay, $fadeOut));
	}

	public function onEmote(Human $from, string $emoteId) : void{
		$this->sendDataPacket(EmotePacket::create($from->getId(), $emoteId, EmotePacket::FLAG_SERVER));
	}

	public function onToastNotification(string $title, string $body) : void{
		$this->sendDataPacket(ToastRequestPacket::create($title, $body));
	}

	private function updatePacketBudget() : void{
		$nowNs = hrtime(true);
		$timeSinceLastUpdateNs = $nowNs - $this->lastPacketBudgetUpdateTimeNs;
		if($timeSinceLastUpdateNs > 50_000_000){
			$ticksSinceLastUpdate = intdiv($timeSinceLastUpdateNs, 50_000_000);
			/*
			 * If the server takes an abnormally long time to process a tick, add the budget for time difference to
			 * compensate. This extra budget may be very large, but it will disappear the next time a normal update
			 * occurs. This ensures that backlogs during a large lag spike don't cause everyone to get kicked.
			 * As long as all the backlogged packets are processed before the next tick, everything should be OK for
			 * clients behaving normally.
			 */
			$this->incomingPacketBatchBudget = min($this->incomingPacketBatchBudget, self::INCOMING_PACKET_BATCH_MAX_BUDGET) + (self::INCOMING_PACKET_BATCH_PER_TICK * 2 * $ticksSinceLastUpdate);
			$this->lastPacketBudgetUpdateTimeNs = $nowNs;
		}
	}

	public function tick() : void{
		if(!$this->isConnected()){
			$this->dispose();
			return;
		}

		if($this->info === null){
			if(time() >= $this->connectTime + 10){
				$this->disconnectWithError(KnownTranslationFactory::pocketmine_disconnect_error_loginTimeout());
			}

			return;
		}

		if($this->player !== null){
			$this->player->doChunkRequests();

			$dirtyAttributes = $this->player->getAttributeMap()->needSend();
			$this->syncAttributes($this->player, $dirtyAttributes);
			foreach($dirtyAttributes as $attribute){
				//TODO: we might need to send these to other players in the future
				//if that happens, this will need to become more complex than a flag on the attribute itself
				$attribute->markSynchronized();
			}
		}

		$this->flushSendBuffer();
	}

	private function flushChunkCache() : void{
		$blobs = array_map(static function(int $hash, string $blob) : ChunkCacheBlob{
			return new ChunkCacheBlob($hash, $blob);
		}, array_keys($this->chunkCacheBlobs), $this->chunkCacheBlobs);

		if(count($blobs) > 0){
			$this->sendDataPacket(ClientCacheMissResponsePacket::create($blobs));
			unset($this->chunkCacheBlobs);
		}
	}
}
