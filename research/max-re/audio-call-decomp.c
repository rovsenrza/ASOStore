
// ================= 0x100edb5e8 =================
// OKMAudioSession::preventAudioOnIncomingCall @ 100edb5e8

/* WARNING: Function: _objc_retainAutoreleasedReturnValue replaced with injection:
   _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x10 bytes */

bool OKMAudioSession::preventAudioOnIncomingCall(ID param_1,SEL param_2)

{
  ID IVar1;
  
  IVar1 = objc_stub::FUN_101e61fe0(param_1);
  return (bool)(IVar1 != 0);
}


// ================= 0x100edb68c =================
// OKMAudioSession::setCallActiveWithPlayer:enableSpeaker:completion: @ 100edb68c

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_retainAutoreleasedReturnValue replaced with injection:
   _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x24 bytes */

void OKMAudioSession::setCallActiveWithPlayer_enableSpeaker_completion_
               (ID param_1,SEL param_2,ID param_3,bool param_4,ID param_5,uint param_6)

{
  ID IVar1;
  
  IVar1 = objc_stub::numberWithBool_
                    ((ID)PTR__OBJC_CLASS___NSNumber_10290b2a8,(ulong)param_4,(ulong)param_4,param_5,
                     (ulong)param_6);
  objc_stub::FUN_101e3ae00(param_1,5,param_3,IVar1,param_5);
  return;
}


// ================= 0x100edb730 =================
// OKMAudioSession::_setActiveWithCategory:player:enableSpeaker:completion: @ 100edb730

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_retainAutoreleasedReturnValue replaced with injection:
   _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x30 bytes */

void OKMAudioSession::_setActiveWithCategory_player_enableSpeaker_completion_
               (ID param_1,SEL param_2,unsigned_long_long param_3,ID param_4,ID param_5,ID param_6,
               undefined4 param_7)

{
  undefined *puVar1;
  ID IVar2;
  ID IVar3;
  ID IVar4;
  undefined8 uVar5;
  code *pcVar6;
  char *pcVar7;
  undefined *local_90;
  undefined8 local_88;
  code *local_80;
  undefined *puStack_78;
  ID local_70;
  unsigned_long_long uStack_68;
  
  puVar1 = PTR__TtC8OMLogger8OMLogger_10290b478;
  if (param_4 == 0) {
    IVar2 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    pcVar7 = "-[OKMAudioSession _setActiveWithCategory:player:enableSpeaker:completion:]";
    IVar3 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    objc_stub::nonFatalWithName_message_dropSymbols_file_line_function_
              ((ID)puVar1,&cf_Assert,&PTR___NSConcreteGlobalBlock_10262cf68,2,IVar2,0x6d,IVar3,
               pcVar7);
    puVar1 = PTR__TtC8OMLogger8OMLogger_10290b478;
  }
  PTR__TtC8OMLogger8OMLogger_10290b478 = puVar1;
  if (param_3 == 0) {
    IVar2 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    pcVar7 = "-[OKMAudioSession _setActiveWithCategory:player:enableSpeaker:completion:]";
    IVar3 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    objc_stub::nonFatalWithName_message_dropSymbols_file_line_function_
              ((ID)puVar1,&cf_Assert,&PTR___NSConcreteGlobalBlock_10262cf88,2,IVar2,0x6e,IVar3,
               pcVar7);
  }
  IVar2 = objc_stub::isMainThread((ID)PTR__OBJC_CLASS___NSThread_10290b980);
  if (((IVar2 & 1) == 0) &&
     (IVar2 = objc_stub::isMainThread((ID)PTR__OBJC_CLASS___NSThread_10290b980),
     puVar1 = PTR__TtC8OMLogger8OMLogger_10290b478, (IVar2 & 1) == 0)) {
    IVar2 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    pcVar7 = "-[OKMAudioSession _setActiveWithCategory:player:enableSpeaker:completion:]";
    IVar3 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    objc_stub::nonFatalWithName_message_dropSymbols_file_line_function_
              ((ID)puVar1,&cf_Assert,&PTR___NSConcreteGlobalBlock_10262cfa8,2,IVar2,0x6f,IVar3,
               pcVar7);
  }
  IVar2 = objc_stub::FUN_101e40f40(param_1);
  if (param_3 == 7) {
    objc_stub::addObject_(IVar2);
    IVar2 = objc_stub::FUN_101e504e0(param_1);
    if (IVar2 == param_4) {
      objc_stub::FUN_101e96140(param_1,0);
    }
    IVar2 = objc_stub::FUN_101e83e60(param_1);
    if (IVar2 == param_4) {
      objc_stub::FUN_101ea2ee0(param_1,0);
    }
  }
  else {
    objc_stub::removeObject_(IVar2,param_4);
  }
  IVar2 = objc_stub::FUN_101e61fe0(param_1);
  if ((IVar2 == 0) || (IVar2 = objc_stub::FUN_101e61fe0(param_1), IVar2 == param_4)) {
    IVar2 = objc_stub::FUN_101e504e0(param_1);
    if (IVar2 != param_4) {
      IVar2 = objc_stub::FUN_101e34420(param_1,param_3);
      puVar1 = PTR__TtC8OMLogger8OMLogger_10290b478;
      if ((IVar2 & 1) != 0) {
        if (param_3 == 7) {
          param_3 = 7;
          param_4 = 0;
        }
        else {
          IVar2 = objc_stub::FUN_101e504e0(param_1);
          objc_stub::FUN_101ea2ee0(param_1,IVar2);
          IVar2 = objc_stub::FUN_101e4ffa0(param_1);
          objc_stub::FUN_101ea2ea0(param_1,IVar2);
        }
        objc_stub::FUN_101e3ae20(param_1,param_3,param_4,param_5,0,param_6);
        return;
      }
      IVar2 = objc_stub::FUN_101e43ac0((ID)PTR__TtC8OMLogger14OMLoggerDomain_10290b520);
      local_90 = PTR___NSConcreteStackBlock_102589898;
      local_88 = DAT_1020cdda8;
      local_80 = FUN_100edbdd4;
      puStack_78 = &DAT_102592098;
      local_70 = param_4;
      uStack_68 = param_3;
      IVar3 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
      pcVar7 = "-[OKMAudioSession _setActiveWithCategory:player:enableSpeaker:completion:]";
      IVar4 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
      objc_stub::logWithLevel_domain_message_file_line_function_
                ((ID)puVar1,3,IVar2,&local_90,IVar3,0x95,IVar4,pcVar7);
      IVar2 = objc_stub::FUN_101e3eb20(param_1);
      IVar3 = objc_stub::FUN_101e4ffa0(param_1);
      if (IVar2 == IVar3) {
        if (param_6 != 0) {
          (**(code **)(param_6 + 0x10))(param_6,0,0);
        }
      }
      else {
        objc_stub::FUN_101e35640(param_1,param_6,0);
      }
      if (param_3 != 1) {
        return;
      }
      IVar2 = objc_stub::FUN_101e4ffa0(param_1);
      if (IVar2 != 5) {
        return;
      }
      objc_stub::FUN_101ea2ee0(param_1,param_4);
      objc_stub::FUN_101ea2ea0(param_1,1);
      return;
    }
    if ((param_3 == 5) && (IVar2 = objc_stub::FUN_101e44780(param_1), (int)IVar2 != 0)) {
      objc_stub::FUN_101e35a40(param_1,5,param_6);
      return;
    }
    IVar2 = objc_stub::FUN_101e38480(param_1,param_3);
    if ((int)IVar2 != 0) {
      objc_stub::FUN_101e35a40(param_1,param_3,param_6);
      objc_stub::FUN_101e95e80(param_1,param_3);
      return;
    }
    IVar2 = objc_stub::FUN_101e3eb20(param_1);
    if (IVar2 != param_3) {
      objc_stub::FUN_101e35640(param_1,param_6,1);
      return;
    }
    if (param_6 == 0) {
      return;
    }
    pcVar6 = *(code **)(param_6 + 0x10);
    uVar5 = 1;
  }
  else {
    if (param_6 == 0) {
      return;
    }
    pcVar6 = *(code **)(param_6 + 0x10);
    uVar5 = 0;
  }
  (*pcVar6)(param_6,uVar5,0);
  return;
}


// ================= 0x100edbe50 =================
// OKMAudioSession::_setActiveWithCategory:player:enableSpeaker:forceCategoryUpdate:completion: @ 100edbe50

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_retainAutoreleasedReturnValue replaced with injection:
   _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x34 bytes */

void OKMAudioSession::_setActiveWithCategory_player_enableSpeaker_forceCategoryUpdate_completion_
               (ID param_1,SEL param_2,unsigned_long_long param_3,ID param_4,ID param_5,bool param_6
               ,ID param_7,undefined4 param_8)

{
  undefined *puVar1;
  ID IVar2;
  ID IVar3;
  ID IVar4;
  char *pcVar5;
  undefined *local_90;
  undefined8 local_88;
  code *local_80;
  undefined *puStack_78;
  ID local_70;
  unsigned_long_long uStack_68;
  
  puVar1 = PTR__TtC8OMLogger8OMLogger_10290b478;
  IVar2 = objc_stub::FUN_101e43ac0((ID)PTR__TtC8OMLogger14OMLoggerDomain_10290b520);
  local_90 = PTR___NSConcreteStackBlock_102589898;
  local_88 = DAT_1020cdda8;
  local_80 = FUN_100edc140;
  puStack_78 = &DAT_102592098;
  local_70 = param_1;
  uStack_68 = param_3;
  IVar3 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
  pcVar5 = 
  "-[OKMAudioSession _setActiveWithCategory:player:enableSpeaker:forceCategoryUpdate:completion:]";
  IVar4 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
  objc_stub::logWithLevel_domain_message_file_line_function_
            ((ID)puVar1,2,IVar2,&local_90,IVar3,0xb0,IVar4,pcVar5);
  puVar1 = PTR__TtC8OMLogger8OMLogger_10290b478;
  if ((param_3 != 7) && (param_4 == 0)) {
    IVar2 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    pcVar5 = 
    "-[OKMAudioSession _setActiveWithCategory:player:enableSpeaker:forceCategoryUpdate:completion:]"
    ;
    IVar3 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    objc_stub::nonFatalWithName_message_dropSymbols_file_line_function_
              ((ID)puVar1,&cf_Assert,&PTR___NSConcreteGlobalBlock_10262cfc8,2,IVar2,0xb1,IVar3,
               pcVar5);
  }
  IVar2 = objc_stub::FUN_101e504e0(param_1);
  objc_stub::FUN_101e96140(param_1,param_4);
  IVar3 = objc_stub::FUN_101e4ffa0(param_1);
  if (((IVar3 == 1) && (param_3 == 1)) && (IVar2 != param_4)) {
    objc_stub::FUN_101e43b00(IVar2);
  }
  IVar2 = objc_stub::FUN_101e4ffa0(param_1);
  objc_stub::FUN_101e95e80(param_1,param_3);
  IVar2 = objc_stub::FUN_101e39220(param_1,param_3,IVar2);
  if (((IVar2 & 1) == 0) && (param_6 == 0)) {
    objc_stub::FUN_101e34180(param_1);
    IVar2 = objc_stub::FUN_101e3eb20(param_1);
    IVar2 = objc_stub::FUN_101e39220(param_1,param_3,IVar2);
    if ((IVar2 & 1) == 0) {
      if (param_7 != 0) {
        (**(code **)(param_7 + 0x10))(param_7,1,0);
      }
    }
    else {
      objc_stub::FUN_101e35640(param_1,param_7,1);
    }
  }
  else {
    objc_stub::FUN_101e95e80(param_1,param_3);
    objc_stub::FUN_101e35a60(param_1,param_3,param_5,param_7);
  }
  return;
}


// ================= 0x100edd64c =================
// OKMAudioSession::setShouldOverrideToEarForVoicePlayback: @ 100edd64c

/* WARNING: Function: _objc_retainAutoreleasedReturnValue replaced with injection:
   _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x14 bytes */

void OKMAudioSession::setShouldOverrideToEarForVoicePlayback_(ID param_1,SEL param_2,bool param_3)

{
  uint uVar1;
  undefined8 uVar2;
  undefined *puVar3;
  undefined *self;
  ID IVar4;
  ID IVar5;
  ID IVar6;
  char *pcVar7;
  undefined *local_d8;
  undefined8 local_d0;
  code *local_c8;
  undefined *puStack_c0;
  undefined1 local_b8;
  undefined *local_b0;
  undefined8 local_a8;
  code *local_a0;
  undefined *puStack_98;
  undefined1 local_90;
  undefined *local_88;
  undefined8 local_80;
  code *local_78;
  undefined *puStack_70;
  undefined1 local_68;
  
  IVar4 = objc_stub::FUN_101e7d9c0(param_1);
  self = PTR__TtC8OMLogger8OMLogger_10290b478;
  if ((bool)IVar4 != param_3) {
    IVar4 = objc_stub::FUN_101e43ac0((ID)PTR__TtC8OMLogger14OMLoggerDomain_10290b520);
    puVar3 = PTR___NSConcreteStackBlock_102589898;
    uVar2 = DAT_1020cef60;
    local_88 = PTR___NSConcreteStackBlock_102589898;
    local_80 = DAT_1020cef60;
    local_78 = FUN_100edd854;
    puStack_70 = &DAT_102590f18;
    local_68 = (undefined1)param_3;
    IVar5 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    pcVar7 = "-[OKMAudioSession setShouldOverrideToEarForVoicePlayback:]";
    IVar6 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    objc_stub::logWithLevel_domain_message_file_line_function_
              ((ID)self,2,IVar4,&local_88,IVar5,0x164,IVar6,pcVar7);
    objc_stub::FUN_101ea12c0(param_1,(ulong)param_3);
    uVar1 = param_3 ^ 1;
    if (((uVar1 & 1) == 0) && (IVar4 = objc_stub::FUN_101e4ffa0(param_1), IVar4 == 2)) {
      objc_stub::FUN_101e95e80(param_1,4);
      IVar4 = objc_stub::FUN_101e4ffa0(param_1);
      IVar5 = objc_stub::numberWithBool_((ID)PTR__OBJC_CLASS___NSNumber_10290b2a8,(ulong)uVar1);
      local_b0 = puVar3;
      local_a8 = uVar2;
      local_a0 = FUN_100edd8c8;
      puStack_98 = &DAT_10262d168;
      local_90 = (char)uVar1;
      objc_stub::FUN_101e35a60(param_1,IVar4,IVar5,&local_b0);
    }
    else {
      IVar4 = objc_stub::FUN_101e4ffa0(param_1);
      if (IVar4 == 4) {
        local_d8 = puVar3;
        local_d0 = uVar2;
        local_c8 = FUN_100edda78;
        puStack_c0 = &DAT_10262d168;
        local_b8 = (char)uVar1;
        objc_stub::FUN_101e3aea0(param_1,(ulong)uVar1,&local_d8);
      }
    }
  }
  return;
}


// ================= 0x100eddc28 =================
// OKMAudioSession::_setDefaultAudioRouteToSpeaker:completion: @ 100eddc28

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x1c bytes */

void OKMAudioSession::_setDefaultAudioRouteToSpeaker_completion_
               (ID param_1,SEL param_2,bool param_3,ID param_4,uint param_5)

{
  ID IVar1;
  undefined *local_60;
  undefined8 local_58;
  code *local_50;
  undefined *puStack_48;
  ID local_40;
  undefined1 local_38;
  
  IVar1 = objc_stub::FUN_101e4ffa0(param_1,(ulong)param_3,param_4,(ulong)param_5);
  if (IVar1 == 0) {
    if (param_4 != 0) {
      (**(code **)(param_4 + 0x10))(param_4,0,0);
    }
  }
  else {
    local_60 = PTR___NSConcreteStackBlock_102589898;
    local_58 = DAT_1020cdda8;
    local_50 = FUN_100eddce4;
    puStack_48 = &DAT_10262d1a8;
    local_38 = (undefined1)param_3;
    local_40 = param_4;
    objc_stub::FUN_101e368c0(param_1,&local_60);
  }
  return;
}


// ================= 0x100ede064 =================
// OKMAudioSession::_actualizeOverrideToSpeakerIfNeeded @ 100ede064

/* WARNING: Function: _objc_retainAutoreleasedReturnValue replaced with injection:
   _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x10 bytes */

void OKMAudioSession::_actualizeOverrideToSpeakerIfNeeded(ID param_1,SEL param_2)

{
  ID IVar1;
  ID IVar2;
  ID IVar3;
  undefined8 local_58;
  undefined8 uStack_50;
  long local_48;
  
  local_48 = *(long *)PTR____stack_chk_guard_102589920;
  IVar1 = objc_stub::FUN_101e504e0(param_1);
  IVar2 = 0;
  if (IVar1 != 0) {
    IVar1 = objc_stub::FUN_101e4ffa0(param_1);
    IVar2 = 0;
    if (IVar1 == 4) {
      IVar1 = objc_stub::FUN_101e7d9c0(param_1);
      IVar2 = objc_stub::sharedInstance((ID)PTR_OKMAudioSession_10290ba30);
      local_58 = *(undefined8 *)PTR__AVAudioSessionPortBuiltInReceiver_10258a2c8;
      uStack_50 = *(undefined8 *)PTR__AVAudioSessionPortBuiltInSpeaker_10258a2d0;
      IVar3 = objc_stub::arrayWithObjects_count_
                        ((ID)PTR__OBJC_CLASS___NSArray_10290b4f0,&local_58,2);
      IVar3 = objc_stub::FUN_101e49e80(IVar2,IVar3);
      IVar2 = 0;
      if ((int)IVar3 != 0) {
        IVar2 = objc_stub::FUN_101e3aea0
                          (param_1,(ulong)((uint)IVar1 ^ 1),&PTR___NSConcreteGlobalBlock_10262d1f8);
      }
    }
  }
  if (*(long *)PTR____stack_chk_guard_102589920 == local_48) {
    return;
  }
                    /* WARNING: Subroutine does not return */
  ___stack_chk_fail(IVar2);
}


// ================= 0x100ede92c =================
// OKMAudioSession::_sessionModeForOKMCategory: @ 100ede92c

/* WARNING: Function: _objc_retainAutoreleaseReturnValue replaced with injection: _objc_retain_fixup
    */
/* Function Stack Size: 0x18 bytes */

ID OKMAudioSession::_sessionModeForOKMCategory_(ID param_1,SEL param_2,unsigned_long_long param_3)

{
  ID *pIVar1;
  
  pIVar1 = (ID *)PTR__AVAudioSessionModeVoiceChat_10258a298;
  if (param_3 != 5) {
    pIVar1 = (ID *)PTR__AVAudioSessionModeDefault_10258a288;
  }
  return *pIVar1;
}


// ================= 0x100ede920 =================
// OKMAudioSession::_sessionCategoryOptionsForOKMCategory: @ 100ede920

/* Function Stack Size: 0x18 bytes */

unsigned_long_long
OKMAudioSession::_sessionCategoryOptionsForOKMCategory_
          (ID param_1,SEL param_2,unsigned_long_long param_3)

{
  return (ulong)(param_3 == 6);
}


// ================= 0x100edf468 =================
// OKMAudioSession::_configureSessionWithCategory:enableSpeaker:completion: @ 100edf468

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* WARNING: Function: _objc_retainAutoreleasedReturnValue replaced with injection:
   _objc_retain_fixup */
/* Function Stack Size: 0x28 bytes */

void OKMAudioSession::_configureSessionWithCategory_enableSpeaker_completion_
               (ID param_1,SEL param_2,unsigned_long_long param_3,ID param_4,ID param_5,uint param_6
               )

{
  undefined *self;
  ID IVar1;
  ID IVar2;
  undefined8 in_x6;
  undefined8 in_x7;
  char *pcVar3;
  undefined *local_90;
  undefined8 local_88;
  code *local_80;
  undefined *puStack_78;
  ID local_70;
  ID local_68;
  unsigned_long_long uStack_60;
  byte local_58;
  
  self = PTR__TtC8OMLogger8OMLogger_10290b478;
  if (param_3 == 0) {
    IVar1 = objc_stub::stringWithFormat_
                      ((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s,param_4,param_5,
                       (ulong)param_6,in_x6,in_x7,"OKMAudioSession.m");
    pcVar3 = "-[OKMAudioSession _configureSessionWithCategory:enableSpeaker:completion:]";
    IVar2 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    objc_stub::nonFatalWithName_message_dropSymbols_file_line_function_
              ((ID)self,&cf_Assert,&PTR___NSConcreteGlobalBlock_10262d298,2,IVar1,0x243,IVar2,pcVar3
              );
    objc_stub::FUN_101e90720(param_1,0);
    if (param_4 != 0) goto LAB_100edf578;
  }
  else {
    objc_stub::FUN_101e90720(param_1,0);
    if (param_4 != 0) {
LAB_100edf578:
      IVar1 = objc_stub::boolValue(param_4);
      local_58 = (byte)IVar1;
      goto LAB_100edf588;
    }
    if (param_3 == 4) {
      IVar1 = objc_stub::FUN_101e7d9c0(param_1);
      local_58 = (byte)IVar1 ^ 1;
      goto LAB_100edf588;
    }
  }
  local_58 = 0;
LAB_100edf588:
  local_90 = PTR___NSConcreteStackBlock_102589898;
  local_88 = DAT_1020cdda8;
  local_80 = FUN_100edf644;
  puStack_78 = &DAT_10262d2e8;
  local_70 = param_1;
  local_68 = param_5;
  uStack_60 = param_3;
  objc_stub::FUN_101e368c0(param_1,&local_90);
  return;
}


// ================= 0x100edea78 =================
// OKMAudioSession::setupAudioSessionWithWebRTCParams: @ 100edea78

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_retainAutoreleasedReturnValue replaced with injection:
   _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x18 bytes */

bool OKMAudioSession::setupAudioSessionWithWebRTCParams_(ID param_1,SEL param_2,ID param_3)

{
  double dVar1;
  undefined *puVar2;
  ID IVar3;
  ID IVar4;
  ID IVar5;
  ID IVar6;
  undefined **ppuVar7;
  undefined8 uVar8;
  undefined8 uVar9;
  double extraout_d0;
  double extraout_d0_00;
  double extraout_d0_01;
  double extraout_d0_02;
  char *pcVar10;
  undefined *local_160;
  undefined8 local_158;
  code *local_150;
  undefined *puStack_148;
  ID local_140;
  undefined *local_138;
  undefined8 local_130;
  code *local_128;
  undefined *puStack_120;
  undefined8 local_118;
  undefined8 local_110;
  undefined *local_108;
  undefined8 local_100;
  code *local_f8;
  undefined *puStack_f0;
  ID local_e8;
  undefined *local_e0;
  undefined8 local_d8;
  code *local_d0;
  undefined *puStack_c8;
  undefined8 local_c0;
  undefined8 local_b8;
  undefined *local_b0;
  undefined8 local_a8;
  code *local_a0;
  undefined *puStack_98;
  undefined8 local_90;
  ID local_88;
  ID local_80;
  undefined8 local_78;
  
  IVar3 = objc_stub::FUN_101ebdcc0((ID)PTR__OBJC_CLASS___RTCAudioSessionConfiguration_10290e0c0);
  IVar4 = objc_stub::FUN_101e77a00(param_1,IVar3,param_3);
  puVar2 = PTR__TtC8OMLogger8OMLogger_10290b478;
  if ((int)IVar4 == 0) {
    IVar4 = objc_stub::FUN_101e43ac0((ID)PTR__TtC8OMLogger14OMLoggerDomain_10290b520);
    IVar5 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    pcVar10 = "-[OKMAudioSession setupAudioSessionWithWebRTCParams:]";
    IVar6 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    objc_stub::logWithLevel_domain_message_file_line_function_
              ((ID)puVar2,2,IVar4,&PTR___NSConcreteGlobalBlock_10262d238,IVar5,0x224,IVar6,pcVar10);
  }
  else {
    uVar8 = *(undefined8 *)PTR__AVAudioSessionCategoryPlayAndRecord_10258a240;
    uVar9 = *(undefined8 *)PTR__AVAudioSessionModeVoiceChat_10258a298;
    IVar4 = objc_stub::FUN_101e48da0(IVar3);
    local_78 = 0;
    IVar4 = objc_stub::FUN_101e93c40(param_3,uVar8,uVar9,IVar4,&local_78);
    puVar2 = PTR__TtC8OMLogger8OMLogger_10290b478;
    if ((IVar4 & 1) == 0) {
      local_b0 = PTR___NSConcreteStackBlock_102589898;
      local_a8 = DAT_1020cdda8;
      local_a0 = FUN_100edf258;
      puStack_98 = &DAT_1025e39f8;
      local_90 = local_78;
      local_88 = param_3;
      local_80 = IVar3;
      IVar3 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
      pcVar10 = "-[OKMAudioSession setupAudioSessionWithWebRTCParams:]";
      IVar4 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
      objc_stub::nonFatalWithName_message_dropSymbols_file_line_function_
                ((ID)puVar2,&cf_AudioSession,&local_b0,2,IVar3,0x220,IVar4,pcVar10);
      return 0;
    }
  }
  objc_stub::FUN_101e81f00(param_3);
  objc_stub::FUN_101e8c2e0(IVar3);
  puVar2 = PTR__TtC8OMLogger8OMLogger_10290b478;
  dVar1 = DAT_1020d6e90;
  if (ABS(extraout_d0 - extraout_d0_00) <= DAT_1020d6e90) {
    IVar4 = objc_stub::FUN_101e43ac0((ID)PTR__TtC8OMLogger14OMLoggerDomain_10290b520);
    IVar5 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    pcVar10 = "-[OKMAudioSession setupAudioSessionWithWebRTCParams:]";
    IVar6 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    objc_stub::logWithLevel_domain_message_file_line_function_
              ((ID)puVar2,2,IVar4,&PTR___NSConcreteGlobalBlock_10262d258,IVar5,0x231,IVar6,pcVar10);
  }
  else {
    objc_stub::FUN_101e8c2e0(IVar3);
    local_b8 = 0;
    IVar4 = objc_stub::FUN_101ea27e0(param_3,&local_b8);
    uVar8 = local_b8;
    puVar2 = PTR__TtC8OMLogger8OMLogger_10290b478;
    IVar5 = objc_stub::FUN_101e43ac0((ID)PTR__TtC8OMLogger14OMLoggerDomain_10290b520);
    if ((IVar4 & 1) == 0) {
      local_e0 = PTR___NSConcreteStackBlock_102589898;
      local_d8 = DAT_1020cdda8;
      local_d0 = FUN_100edf2e8;
      puStack_c8 = &DAT_102590bc8;
      local_c0 = uVar8;
      IVar4 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
      pcVar10 = "-[OKMAudioSession setupAudioSessionWithWebRTCParams:]";
      IVar6 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
      ppuVar7 = &local_e0;
      uVar8 = 4;
      uVar9 = 0x22c;
    }
    else {
      local_108 = PTR___NSConcreteStackBlock_102589898;
      local_100 = DAT_1020cdda8;
      local_f8 = FUN_100edf350;
      puStack_f0 = &DAT_102590bc8;
      local_e8 = IVar3;
      IVar4 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
      pcVar10 = "-[OKMAudioSession setupAudioSessionWithWebRTCParams:]";
      IVar6 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
      ppuVar7 = &local_108;
      uVar8 = 2;
      uVar9 = 0x22e;
    }
    objc_stub::logWithLevel_domain_message_file_line_function_
              ((ID)puVar2,uVar8,IVar5,ppuVar7,IVar4,uVar9,IVar6,pcVar10);
  }
  objc_stub::FUN_101e81de0(param_3);
  objc_stub::FUN_101e6a8e0(IVar3);
  puVar2 = PTR__TtC8OMLogger8OMLogger_10290b478;
  if (ABS(extraout_d0_01 - extraout_d0_02) <= dVar1) {
    IVar3 = objc_stub::FUN_101e43ac0((ID)PTR__TtC8OMLogger14OMLoggerDomain_10290b520);
    IVar4 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    pcVar10 = "-[OKMAudioSession setupAudioSessionWithWebRTCParams:]";
    IVar5 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
    objc_stub::logWithLevel_domain_message_file_line_function_
              ((ID)puVar2,2,IVar3,&PTR___NSConcreteGlobalBlock_10262d278,IVar4,0x23c,IVar5,pcVar10);
  }
  else {
    objc_stub::FUN_101e6a8e0(IVar3);
    local_110 = 0;
    IVar4 = objc_stub::FUN_101ea2740(param_3,&local_110);
    uVar8 = local_110;
    puVar2 = PTR__TtC8OMLogger8OMLogger_10290b478;
    IVar5 = objc_stub::FUN_101e43ac0((ID)PTR__TtC8OMLogger14OMLoggerDomain_10290b520);
    if ((IVar4 & 1) == 0) {
      local_138 = PTR___NSConcreteStackBlock_102589898;
      local_130 = DAT_1020cdda8;
      local_128 = FUN_100edf3a8;
      puStack_120 = &DAT_102590bc8;
      local_118 = uVar8;
      IVar3 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
      pcVar10 = "-[OKMAudioSession setupAudioSessionWithWebRTCParams:]";
      IVar4 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
      ppuVar7 = &local_138;
      uVar8 = 4;
      uVar9 = 0x237;
    }
    else {
      local_160 = PTR___NSConcreteStackBlock_102589898;
      local_158 = DAT_1020cdda8;
      local_150 = FUN_100edf410;
      puStack_148 = &DAT_102590bc8;
      local_140 = IVar3;
      IVar3 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
      pcVar10 = "-[OKMAudioSession setupAudioSessionWithWebRTCParams:]";
      IVar4 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
      ppuVar7 = &local_160;
      uVar8 = 2;
      uVar9 = 0x239;
    }
    objc_stub::logWithLevel_domain_message_file_line_function_
              ((ID)puVar2,uVar8,IVar5,ppuVar7,IVar3,uVar9,IVar4,pcVar10);
  }
  return 1;
}


// ================= 0x100ee0380 =================
// OKMAudioSession::setDefaultAudioRouteToOutput:uid:completion: @ 100ee0380

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_retainAutoreleasedReturnValue replaced with injection:
   _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x28 bytes */

void OKMAudioSession::setDefaultAudioRouteToOutput_uid_completion_
               (ID param_1,SEL param_2,unsigned_long_long param_3,ID param_4,ID param_5,
               undefined4 param_6)

{
  undefined8 uVar1;
  undefined *puVar2;
  undefined *self;
  ID IVar3;
  ID IVar4;
  ID IVar5;
  char *pcVar6;
  undefined *local_d8;
  undefined8 local_d0;
  code *local_c8;
  undefined *puStack_c0;
  ID local_b8;
  ID local_b0;
  unsigned_long_long uStack_a8;
  undefined *local_a0;
  undefined8 local_98;
  code *local_90;
  undefined *puStack_88;
  ID local_80;
  unsigned_long_long local_78;
  
  self = PTR__TtC8OMLogger8OMLogger_10290b478;
  IVar3 = objc_stub::FUN_101e43ac0((ID)PTR__TtC8OMLogger14OMLoggerDomain_10290b520);
  puVar2 = PTR___NSConcreteStackBlock_102589898;
  uVar1 = DAT_1020cdda8;
  local_a0 = PTR___NSConcreteStackBlock_102589898;
  local_98 = DAT_1020cdda8;
  local_90 = FUN_100ee0548;
  puStack_88 = &DAT_102592098;
  local_80 = param_4;
  local_78 = param_3;
  IVar4 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
  pcVar6 = "-[OKMAudioSession setDefaultAudioRouteToOutput:uid:completion:]";
  IVar5 = objc_stub::stringWithFormat_((ID)PTR__OBJC_CLASS___NSString_10290b448,&cf__s);
  objc_stub::logWithLevel_domain_message_file_line_function_
            ((ID)self,2,IVar3,&local_a0,IVar4,0x2a6,IVar5,pcVar6);
  local_d8 = puVar2;
  local_d0 = uVar1;
  local_c8 = FUN_100ee05c8;
  puStack_c0 = &DAT_10262d138;
  local_b8 = param_4;
  local_b0 = param_5;
  uStack_a8 = param_3;
  objc_stub::FUN_101e368c0(param_1,&local_d8);
  return;
}


// ================= 0x100ee193c =================
// OKMAudioSession::handleRouteChangeNotification: @ 100ee193c

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_retainAutoreleasedReturnValue replaced with injection:
   _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x18 bytes */

void OKMAudioSession::handleRouteChangeNotification_(ID param_1,SEL param_2,ID param_3)

{
  undefined *self;
  ID IVar1;
  undefined *local_78;
  undefined8 local_70;
  code *local_68;
  undefined *puStack_60;
  undefined1 auStack_58 [8];
  ID local_50;
  undefined1 auStack_48 [8];
  
  IVar1 = objc_stub::userInfo(param_3);
  IVar1 = objc_stub::objectForKeyedSubscript_
                    (IVar1,*(undefined8 *)PTR__AVAudioSessionRouteChangeReasonKey_10258a320);
  IVar1 = objc_stub::unsignedIntegerValue(IVar1);
  _objc_initWeak(auStack_48,param_1);
  self = PTR__OBJC_CLASS___RTCDispatcher_10290e0c8;
  local_78 = PTR___NSConcreteStackBlock_102589898;
  local_70 = DAT_1020cdda8;
  local_68 = FUN_100ee1a5c;
  puStack_60 = &DAT_1025e36b0;
  _objc_copyWeak(auStack_58,auStack_48);
  local_50 = IVar1;
  objc_stub::FUN_101e54fe0((ID)self,2,&local_78);
  _objc_destroyWeak(auStack_58);
  _objc_destroyWeak(auStack_48);
  return;
}


// ================= 0x100bab980 =================
// _TtC7OMCalls17CallKitController::providerDidBegin: @ 100bab980

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x18 bytes */

void _TtC7OMCalls17CallKitController::providerDidBegin_(ID param_1,SEL param_2,ID param_3)

{
  FUN_100fb0b70();
  FUN_100fae71c(*(undefined8 *)param_1,FUN_100bab964,0,0xd00000000000001f,0x8000000102006110,0x108,
                0xd000000000000014,0x8000000102006870);
  return;
}


// ================= 0x100bac5b8 =================
// _TtC7OMCalls17CallKitController::provider:performStartCallAction: @ 100bac5b8

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x20 bytes */

void _TtC7OMCalls17CallKitController::provider_performStartCallAction_
               (ID param_1,SEL param_2,ID param_3,ID param_4)

{
  FUN_100baf918(param_4);
  return;
}


// ================= 0x100bad28c =================
// _TtC7OMCalls17CallKitController::provider:performAnswerCallAction: @ 100bad28c

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x20 bytes */

void _TtC7OMCalls17CallKitController::provider_performAnswerCallAction_
               (ID param_1,SEL param_2,ID param_3,ID param_4)

{
  FUN_100bb0110(param_4);
  return;
}


// ================= 0x100bad868 =================
// _TtC7OMCalls17CallKitController::provider:didActivateAudioSession: @ 100bad868

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x20 bytes */

void _TtC7OMCalls17CallKitController::provider_didActivateAudioSession_
               (ID param_1,SEL param_2,ID param_3,ID param_4)

{
  FUN_100bb0950(param_1);
  return;
}


// ================= 0x100bad8e8 =================
// _TtC7OMCalls17CallKitController::provider:didDeactivateAudioSession: @ 100bad8e8

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x20 bytes */

void _TtC7OMCalls17CallKitController::provider_didDeactivateAudioSession_
               (ID param_1,SEL param_2,ID param_3,ID param_4)

{
  FUN_100bb0a00(param_1);
  return;
}


// ================= 0x100019ad8 =================
// _TtC3App16VoIPPushRegistry::pushRegistry:didReceiveIncomingPushWithPayload:forType:withCompletionHandler: @ 100019ad8

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* Function Stack Size: 0x30 bytes */

void _TtC3App16VoIPPushRegistry::
     pushRegistry_didReceiveIncomingPushWithPayload_forType_withCompletionHandler_
               (ID param_1,SEL param_2,ID param_3,ID param_4,ID param_5,ID param_6,
               undefined4 param_7)

{
  void *pvVar1;
  undefined *puVar2;
  
  pvVar1 = __Block_copy((void *)param_6);
  puVar2 = &DAT_1025903c0;
  _swift_allocObject(&DAT_1025903c0,0x18,7);
  *(void **)(puVar2 + 0x10) = pvVar1;
  FUN_10001b3dc(param_4,param_5,FUN_10001bb34,puVar2);
  _swift_release(puVar2);
  return;
}

